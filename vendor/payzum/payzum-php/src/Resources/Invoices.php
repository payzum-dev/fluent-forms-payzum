<?php

declare(strict_types=1);

namespace Payzum\Resources;

use Payzum\Client;
use Payzum\Errors\PayzumException;

/**
 * The buyer-facing invoice status endpoint.
 *
 * Unauthenticated, with its own rate limit keyed by client IP, so polling it
 * does not eat the merchant's 60/minute budget.
 *
 * Two things worth knowing. The invoice id is a bearer token — roughly 120 bits
 * of entropy, and whoever holds it can read that invoice's status. Keep it out
 * of access logs and shareable URLs. And unlike the merchant surface, the
 * amounts here are exact decimal strings, so this is the surface to read when
 * you need precision.
 */
final class Invoices
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Fetch buyer-facing status. No API key is sent.
     *
     * @return array<string, mixed> camelCase names, decimal-string amounts,
     *         epoch-millisecond timestamps, and the buyer status vocabulary
     *         (pending|partial|paid|overpaid|expired|cancelled).
     */
    public function status(string $paymentId): array
    {
        if (preg_match('/^pzi_[a-z0-9]{24,32}$/', $paymentId) !== 1) {
            throw new PayzumException(
                sprintf('"%s" is not a Payzum invoice id (expected pzi_ + 24-32 lowercase alphanumerics).', $paymentId),
            );
        }

        return $this->client->request(
            method: 'GET',
            path: '/v1/invoices/' . $paymentId . '/status',
            authenticated: false,
        );
    }
}
