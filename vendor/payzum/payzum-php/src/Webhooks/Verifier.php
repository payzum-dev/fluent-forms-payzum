<?php

declare(strict_types=1);

namespace Payzum\Webhooks;

use Payzum\Errors\SignatureException;
use Payzum\Json;

/**
 * Verification for the three webhook families Payzum sends.
 *
 * They are NOT interchangeable, and confusing them is the most expensive
 * mistake you can make with this API — 20 of the 21 Payzum cart plugins read
 * `x-payzum-signature` (the mass-payout header) for a payment IPN. The
 * signature never verifies, deliveries 401, the gateway retries five times and
 * dead-letters, and the order is silently never fulfilled.
 *
 *   payment IPN       HMAC-SHA-512  x-nowpayments-sig    key-sorted JSON
 *   CoinPayments IPN  HMAC-SHA-512  HMAC                 form-urlencoded
 *   mass payout       HMAC-SHA-256  X-Payzum-Signature   JSON, not sorted
 *
 * Header names are fixed and owned by this class. They are deliberately not
 * constructor arguments: a setting is an invitation to fill it with the wrong
 * value, which is precisely how the plugins went wrong.
 */
final class Verifier
{
    /**
     * The five event types the payment IPN emits — five, not two. An
     * integration that handles only paid/expired silently discards the three
     * others, including the two that matter for security.
     *
     * There is deliberately no `invoice.partial` and no cancellation event:
     * the gateway sends no IPN for those — they are only observable by polling.
     */
    public const IPN_EVENT_TYPES = [
        'invoice.paid',
        'invoice.expired',
        'late_deposit_received',
        'wrong_token_received',
        'suspicious_token_received',
    ];

    /** Payment IPN. Fixed — see the class docblock. */
    private const HEADER_PAYMENT_IPN = 'x-nowpayments-sig';

    /** Mass-payout webhooks. Note this is NOT the payment IPN header. */
    private const HEADER_MASS_PAYOUT = 'x-payzum-signature';

    /** CoinPayments-mode merchants. */
    private const HEADER_COINPAYMENTS = 'hmac';

    private const HEADER_EVENT_ID = 'x-payzum-event-id';

    public function __construct(
        private readonly string $secret,
        /** Reject events older (or further in the future) than this. */
        private readonly int $replayWindowSeconds = 600,
    ) {
        if ($secret === '') {
            throw new SignatureException(
                SignatureException::REASON_MALFORMED,
                'Webhook secret is empty. It is shown once, at merchant creation or rotation.',
            );
        }
    }

    /**
     * Verify a payment IPN and return its decoded payload.
     *
     * @param string $rawBody The bytes exactly as received, before any parsing.
     * @param array<string, string|list<string>> $headers
     * @param int|null $now Epoch seconds; injectable so tests never touch the clock.
     * @return array<string, mixed>
     * @throws SignatureException
     */
    public function verifyPaymentIpn(string $rawBody, array $headers, ?int $now = null): array
    {
        $this->assertSignature($rawBody, $headers, self::HEADER_PAYMENT_IPN, 'sha512');

        $payload = Json::decodeLossless($rawBody);
        $this->assertFresh($payload['event_at'] ?? null, $now);

        return $payload;
    }

    /**
     * Verify a mass-payout webhook and return its decoded payload.
     *
     * @param array<string, string|list<string>> $headers
     * @return array<string, mixed>
     * @throws SignatureException
     */
    public function verifyMassPayout(string $rawBody, array $headers, ?int $now = null): array
    {
        $this->assertSignature($rawBody, $headers, self::HEADER_MASS_PAYOUT, 'sha256');

        $payload = Json::decodeLossless($rawBody);
        $this->assertFresh($payload['eventAt'] ?? null, $now);

        return $payload;
    }

    /**
     * Verify a CoinPayments-shaped IPN and return its decoded form fields.
     *
     * There is no freshness check here because the CoinPayments payload carries
     * no timestamp — a replay window is simply not possible. Deduplicating on
     * `ipn_id` is the only defence available, and it is the caller's job.
     *
     * @param array<string, string|list<string>> $headers
     * @return array<string, string>
     * @throws SignatureException
     */
    public function verifyCoinPaymentsIpn(string $rawBody, array $headers): array
    {
        $this->assertSignature($rawBody, $headers, self::HEADER_COINPAYMENTS, 'sha512');

        parse_str($rawBody, $fields);

        /** @var array<string, string> $fields */
        return $fields;
    }

    /**
     * The id to deduplicate on, if the delivery carries one.
     *
     * Retries reuse it, so a second delivery with the same id must be a no-op
     * rather than a second fulfilment. CoinPayments deliveries have no such
     * header — use the `ipn_id` field from the body instead.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function eventId(array $headers): ?string
    {
        return $this->header($headers, self::HEADER_EVENT_ID);
    }

    /** @param array<string, string|list<string>> $headers */
    private function assertSignature(
        string $rawBody,
        array $headers,
        string $headerName,
        string $algo,
    ): void {
        $provided = $this->header($headers, $headerName);

        if ($provided === null || $provided === '') {
            throw new SignatureException(
                SignatureException::REASON_MISSING_HEADER,
                sprintf(
                    'Missing "%s" header. If you are seeing "x-payzum-signature" instead, '
                    . 'that is the mass-payout header and this is a different webhook family.',
                    $headerName,
                ),
            );
        }

        $expected = hash_hmac($algo, $rawBody, $this->secret);

        // Hex is case-insensitive, so normalise before comparing. Compare the
        // lengths first: hash_equals on differing lengths leaks nothing but the
        // check keeps the intent explicit and mirrors the byte-wise compare
        // every other SDK does.
        $given = strtolower(trim($provided));
        if (strlen($given) !== strlen($expected) || !hash_equals($expected, $given)) {
            throw new SignatureException(
                SignatureException::REASON_BAD_SIGNATURE,
                'Webhook signature does not match. Verify against the RAW request bytes, '
                . 'before parsing the JSON — re-serialising reorders keys and breaks it.',
            );
        }
    }

    private function assertFresh(mixed $eventAt, ?int $now): void
    {
        if (!is_int($eventAt) && !is_string($eventAt)) {
            throw new SignatureException(
                SignatureException::REASON_MALFORMED,
                'Signed payload carries no usable timestamp, so replay cannot be ruled out.',
            );
        }

        $ts = (int) $eventAt;
        $now ??= time();

        // Guard both directions: a far-future timestamp is clock skew or a
        // forged replay, and neither should be accepted.
        if (abs($now - $ts) > $this->replayWindowSeconds) {
            throw new SignatureException(
                SignatureException::REASON_STALE_EVENT,
                sprintf(
                    'Event timestamp %d is outside the %ds window around %d. '
                    . 'The signature is valid — this is a replay guard.',
                    $ts,
                    $this->replayWindowSeconds,
                    $now,
                ),
            );
        }
    }

    /**
     * Case-insensitive header lookup, per RFC 9110.
     *
     * Frameworks normalise header casing differently — PSR-7 preserves it,
     * $_SERVER upper-cases it, some proxies lower-case it. Matching
     * case-sensitively would work in development and fail in production.
     *
     * @param array<string, string|list<string>> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        $want = strtolower($name);

        foreach ($headers as $key => $value) {
            // Also accept the CGI form: HTTP_X_NOWPAYMENTS_SIG.
            $normalised = strtolower(str_replace('_', '-', (string) $key));
            if ($normalised === $want || $normalised === 'http-' . $want) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }

        return null;
    }
}
