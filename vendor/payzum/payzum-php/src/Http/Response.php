<?php

declare(strict_types=1);

namespace Payzum\Http;

/** A raw HTTP response. The body stays a string so signatures and decimals survive. */
final class Response
{
    /** @param array<string, string> $headers Lower-cased names. */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Seconds the server asked us to wait, when it said so. */
    public function retryAfterSeconds(): ?int
    {
        $value = $this->header('retry-after');

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /** Set only when the API replayed an earlier idempotent request. */
    public function isIdempotentReplay(): bool
    {
        return $this->header('x-payzum-idempotent-replay') === 'true';
    }
}
