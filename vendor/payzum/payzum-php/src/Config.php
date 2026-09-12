<?php

declare(strict_types=1);

namespace Payzum;

use Payzum\Errors\PayzumException;

/**
 * Client configuration, validated once at construction.
 */
final class Config
{
    public const PRODUCTION = 'https://merchant.payzum.com';

    /** Isolated data and separate API keys from production. */
    public const SANDBOX = 'https://staging.payzum.com';

    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = self::PRODUCTION,
        /** Per-request timeout. Every external call needs one. */
        public readonly int $timeoutSeconds = 30,
        /** Attempts after the first, for retryable failures only. */
        public readonly int $maxRetries = 3,
    ) {
        // The server checks length >= 32 and nothing else — no hex, no fixed
        // 64. Being stricter here would reject keys the API accepts, which is
        // a worse failure than a round trip: it is unexplainable from the
        // caller's side.
        if (strlen($apiKey) < 32) {
            throw new PayzumException(
                'Payzum API key looks wrong: it must be at least 32 characters. '
                . 'Keys come from Dashboard → Settings → API Keys.',
            );
        }

        if (!str_starts_with($baseUrl, 'https://')) {
            throw new PayzumException('baseUrl must be https — API keys travel in a header.');
        }

        if ($timeoutSeconds < 1) {
            throw new PayzumException('timeoutSeconds must be at least 1.');
        }
    }

    /** Point the client at staging. */
    public static function sandbox(string $apiKey): self
    {
        return new self($apiKey, self::SANDBOX);
    }
}
