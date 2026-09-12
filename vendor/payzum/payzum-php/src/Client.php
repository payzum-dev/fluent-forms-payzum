<?php

declare(strict_types=1);

namespace Payzum;

use Payzum\Errors\ApiException;
use Payzum\Http\CurlTransport;
use Payzum\Http\Response;
use Payzum\Http\Transport;
use Payzum\Http\TransportException;

/**
 * HTTP client for the Payzum API: auth, retries, backoff, typed errors.
 *
 * Retry policy, and why it is narrower than it looks like it could be:
 *
 * Only three of the sixteen error codes are retryable. QUOTA_EXCEEDED arrives
 * as a 429 and is NOT one of them — it means too many invoices are open, so
 * retrying makes the situation worse. Treating every 429 as retryable is the
 * obvious mistake.
 *
 * More importantly, an unsafe request is never retried automatically. Invoice
 * creation accepts an Idempotency-Key, but the API documents that as
 * best-effort with roughly 60 seconds of eventual consistency, and it does not
 * enforce order_id uniqueness. So a blind retry can create a second real
 * invoice, and a second charge. Without a key the SDK refuses to retry at all;
 * with one it still waits out the consistency window first.
 */
final class Client
{
    private const CONSISTENCY_WINDOW_SECONDS = 60;

    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport = new CurlTransport(),
        /** Injectable so tests exercise backoff without waiting for it. */
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Perform a request and return the decoded body.
     *
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $json
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        array $extraHeaders = [],
        bool $authenticated = true,
        bool $retryable = true,
        /** Keys whose string values must go out as exact JSON numbers. */
        array $exactNumericFields = [],
        /**
         * Floor for every backoff of this request. Used by invoice creation to
         * wait out the idempotency consistency window before the first retry.
         */
        int $minRetryDelaySeconds = 0,
    ): array {
        $headers = $this->buildHeaders($extraHeaders, $authenticated, $json !== null);

        $body = null;
        if ($json !== null) {
            // Amounts stay strings all the way here and become numbers only in
            // the encoded text, so no float ever touches them (see Json).
            $body = Json::encodeWithExactNumbers($json, $exactNumericFields);
        }

        $url = rtrim($this->config->baseUrl, '/') . $path;
        $attempt = 0;

        while (true) {
            try {
                $response = $this->transport->send(
                    $method,
                    $url,
                    $headers,
                    $query,
                    $body,
                    $this->config->timeoutSeconds,
                );
            } catch (TransportException $e) {
                // No response came back, so the server's state is unknown.
                if (!$retryable || $attempt >= $this->config->maxRetries) {
                    throw $e;
                }
                $this->backoff(++$attempt, null, $minRetryDelaySeconds);
                continue;
            }

            if ($response->status >= 200 && $response->status < 300) {
                return $response->body === '' ? [] : Json::decodeLossless($response->body);
            }

            $error = $this->toApiException($response);

            if (!$retryable || !$error->isRetryable() || $attempt >= $this->config->maxRetries) {
                throw $error;
            }

            $this->backoff(++$attempt, $error->retryAfterSeconds, $minRetryDelaySeconds);
        }
    }

    /**
     * Create an invoice.
     *
     * Retries are enabled only when the caller supplies an Idempotency-Key, and
     * even then the first backoff clears the consistency window rather than
     * firing immediately. See the class docblock for why.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPayment(array $payload, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
                throw new Errors\PayzumException('Idempotency-Key must be 1-255 characters.');
            }
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->request(
            method: 'POST',
            path: '/v1/payment',
            json: $payload,
            extraHeaders: $headers,
            retryable: $idempotencyKey !== null,
            exactNumericFields: ['price_amount'],
            minRetryDelaySeconds: self::CONSISTENCY_WINDOW_SECONDS,
        );
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function buildHeaders(array $extra, bool $authenticated, bool $hasBody): array
    {
        $headers = $extra + [
            'Accept' => 'application/json',
            'User-Agent' => 'payzum-php/' . Payzum::VERSION,
        ];

        if ($authenticated) {
            $headers['x-api-key'] = $this->config->apiKey;
        }

        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    private function toApiException(Response $response): ApiException
    {
        try {
            $body = Json::decode($response->body);
        } catch (Errors\PayzumException) {
            $body = ['code' => 'UNKNOWN', 'message' => trim($response->body) ?: 'Non-JSON error response'];
        }

        return ApiException::fromResponse($response->status, $body, $response->retryAfterSeconds());
    }

    /**
     * Honour Retry-After when the server sends it; otherwise exponential
     * backoff with jitter, so a fleet of workers does not resynchronise into a
     * thundering herd after a shared outage. Either way the delay never dips
     * below the per-request floor (the consistency window, for creates).
     */
    private function backoff(int $attempt, ?int $retryAfter, int $floorSeconds = 0): void
    {
        $base = max($retryAfter ?? (2 ** $attempt), $floorSeconds);
        $jitter = random_int(0, 1000) / 1000;
        ($this->sleeper)((int) ceil($base + $jitter));
    }

    /** Seconds to wait before the first safe retry of a create call. */
    public static function consistencyWindowSeconds(): int
    {
        return self::CONSISTENCY_WINDOW_SECONDS;
    }
}
