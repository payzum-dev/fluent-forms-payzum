<?php

declare(strict_types=1);

namespace Payzum\Errors;

/**
 * A structured error returned by the API.
 *
 * The canonical envelope is {statusCode, code, message}. The buyer surface adds
 * a legacy `error` slug (invalid_invoice_id | rate_limited | not_found)
 * additively — it is exposed here but branching on `code` is preferred.
 */
final class ApiException extends PayzumException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?ErrorCode $errorCode,
        public readonly string $rawCode,
        string $message,
        public readonly ?string $legacySlug = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(
            sprintf('[%d %s] %s', $statusCode, $rawCode, $message),
        );
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(int $statusCode, array $body, ?int $retryAfter): self
    {
        $raw = is_string($body['code'] ?? null) ? $body['code'] : 'UNKNOWN';
        $slug = is_string($body['error'] ?? null) ? $body['error'] : null;
        $message = is_string($body['message'] ?? null) ? $body['message'] : 'Unknown API error';

        return new self(
            statusCode: $statusCode,
            errorCode: ErrorCode::tryFromString($raw),
            rawCode: $raw,
            message: $message,
            legacySlug: $slug,
            retryAfterSeconds: $retryAfter,
        );
    }

    public function isRetryable(): bool
    {
        return $this->errorCode?->isRetryable() ?? false;
    }
}
