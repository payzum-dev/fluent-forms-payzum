<?php

declare(strict_types=1);

namespace Payzum\Errors;

/**
 * A webhook failed verification and must not be acted on.
 *
 * Verification throws rather than returning a bool on purpose. A bool can be
 * ignored by accident — `verify($body, $headers);` on its own line compiles,
 * runs, and fulfils the order. An exception cannot be ignored by accident.
 */
final class SignatureException extends PayzumException
{
    public const REASON_MISSING_HEADER = 'missing_header';
    public const REASON_BAD_SIGNATURE = 'bad_signature';
    public const REASON_STALE_EVENT = 'stale_event';
    public const REASON_MALFORMED = 'malformed_payload';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
