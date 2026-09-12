<?php

declare(strict_types=1);

namespace Payzum;

/**
 * One canonical status, mapped from the two vocabularies the API uses.
 *
 * The merchant surface emits five values and the buyer surface emits six, with
 * no name in common. Without a single enum every integrator invents their own
 * mapping, and the interesting cases are exactly where they get it wrong.
 *
 * Deliberately absent: overpaid. The gateway treats overpayment as internal —
 * excess and fees go to treasury and the merchant is told "paid". Overpaid
 * cases are resolved manually by support when a merchant raises one. The
 * merchant surface therefore has no way to detect it, and this SDK does not
 * invent one. The buyer surface does expose it; that asymmetry is intentional.
 */
enum PaymentStatus: string
{
    case Waiting = 'waiting';
    case PartiallyPaid = 'partially_paid';
    case Finished = 'finished';
    case Expired = 'expired';
    case Failed = 'failed';

    /**
     * Map `payment_status` from the merchant surface.
     *
     * Only these five ever appear. `unconfirmed` does not exist — older docs
     * listed it. `overpaid` arrives as `finished` and `cancelled` as `failed`.
     */
    public static function fromMerchant(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new Errors\PayzumException(
                sprintf(
                    'Unknown merchant payment_status "%s". Expected one of: %s',
                    $value,
                    implode(', ', array_column(self::cases(), 'value')),
                ),
            );
    }

    /**
     * Map `status` from the buyer surface (GET /v1/invoices/{id}/status).
     *
     * `overpaid` collapses to Finished and `cancelled` to Failed, so that both
     * surfaces produce the same canonical value for the same invoice.
     */
    public static function fromBuyer(string $value): self
    {
        return match ($value) {
            'pending' => self::Waiting,
            'partial' => self::PartiallyPaid,
            'paid', 'overpaid' => self::Finished,
            'expired' => self::Expired,
            'cancelled' => self::Failed,
            default => throw new Errors\PayzumException(
                sprintf('Unknown buyer status "%s"', $value),
            ),
        };
    }

    /** No further transitions happen from here. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Finished, self::Expired, self::Failed => true,
            self::Waiting, self::PartiallyPaid => false,
        };
    }

    /** The invoice was paid in full. Safe to fulfil. */
    public function isPaid(): bool
    {
        return $this === self::Finished;
    }
}
