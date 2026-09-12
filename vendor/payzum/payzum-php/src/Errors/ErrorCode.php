<?php

declare(strict_types=1);

namespace Payzum\Errors;

/**
 * The 16 error codes the API emits, and whether retrying is sane.
 *
 * The API's own guidance is to branch on `code`, never on `message` — message
 * text changes between releases.
 */
enum ErrorCode: string
{
    case ApiKeyMissing = 'API_KEY_MISSING';
    case ApiKeyMalformed = 'API_KEY_MALFORMED';
    case ApiKeyNotFound = 'API_KEY_NOT_FOUND';
    case MerchantSuspended = 'MERCHANT_SUSPENDED';
    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
    case InvalidRequest = 'INVALID_REQUEST';
    case CurrencyNotSupported = 'CURRENCY_NOT_SUPPORTED';
    case RateProviderDown = 'RATE_PROVIDER_DOWN';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case PaymentNotFound = 'PAYMENT_NOT_FOUND';
    case InternalError = 'INTERNAL_ERROR';
    case AmountBelowMinimum = 'AMOUNT_BELOW_MINIMUM';
    case NoEligibleCurrencies = 'NO_ELIGIBLE_CURRENCIES';
    case RangeTooLarge = 'RANGE_TOO_LARGE';
    case ExportHistoryUnavailable = 'EXPORT_HISTORY_UNAVAILABLE';
    case ReportNotFound = 'REPORT_NOT_FOUND';

    /**
     * Only three are worth retrying.
     *
     * QuotaExceeded is deliberately excluded even though it arrives as a 429:
     * it means too many invoices are open at once, so retrying makes it worse,
     * not better. Treating every 429 as retryable is the obvious mistake here.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimitExceeded,
            self::InternalError,
            self::RateProviderDown => true,
            default => false,
        };
    }

    /** Unknown codes are surfaced rather than swallowed — the API may add some. */
    public static function tryFromString(?string $code): ?self
    {
        return $code === null ? null : self::tryFrom($code);
    }
}
