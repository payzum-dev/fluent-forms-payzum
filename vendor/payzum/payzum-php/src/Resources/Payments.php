<?php

declare(strict_types=1);

namespace Payzum\Resources;

use Payzum\Client;
use Payzum\Errors\PayzumException;

/** Invoices on the merchant surface. */
final class Payments
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Create an invoice.
     *
     * Amounts are accepted as strings so nothing is rounded on the way in.
     *
     * @param string $payCurrency An NP code (`usdttrc20`), a bare symbol
     *        combined with `$network`, or `"all"` to let the buyer pick.
     * @param string|null $idempotencyKey Supply one to make a retry safe. See
     *        Client for why a retry without it is never automatic.
     * @return array<string, mixed>
     */
    public function create(
        string $priceAmount,
        string $priceCurrency,
        string $payCurrency,
        ?string $orderId = null,
        ?string $orderDescription = null,
        ?string $network = null,
        ?string $ipnCallbackUrl = null,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
        string $pricingMode = 'fiat',
        ?string $idempotencyKey = null,
    ): array {
        // The API rejects this combination with INVALID_REQUEST; catching it
        // here saves a round trip and names the problem plainly.
        if ($pricingMode === 'direct' && $payCurrency === 'all') {
            throw new PayzumException(
                'pricing_mode "direct" cannot be combined with pay_currency "all": '
                . 'a direct price needs to know which asset it is denominated in.',
            );
        }

        if (!is_numeric($priceAmount) || (float) $priceAmount <= 0) {
            throw new PayzumException('priceAmount must be a positive numeric string.');
        }

        $payload = array_filter([
            // Stays a string here on purpose. Client encodes it as an exact
            // JSON number; casting to float would round it on the way out.
            'price_amount' => $priceAmount,
            'price_currency' => $priceCurrency,
            'pay_currency' => $payCurrency,
            'pricing_mode' => $pricingMode,
            'order_id' => $orderId,
            'order_description' => $orderDescription,
            'network' => $network,
            'ipn_callback_url' => $ipnCallbackUrl,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], static fn (mixed $v): bool => $v !== null);

        return $this->client->createPayment($payload, $idempotencyKey);
    }

    /**
     * Read one invoice.
     *
     * Accepts either the Payzum `payment_id` or your own `order_id`, so there
     * is no need to keep a mapping table on your side.
     *
     * @return array<string, mixed>
     */
    public function get(string $idOrOrderId): array
    {
        return $this->client->request('GET', '/v1/payment/' . rawurlencode($idOrOrderId));
    }

    /**
     * List invoices, newest first by default.
     *
     * Pagination is `page` (zero-based) plus `limit` — not an offset. Drafts
     * created with pay_currency="all" that the buyer never resolved are not
     * listed; fetch those by id.
     *
     * @return array<string, mixed> Keys: data, limit, page, pagesCount, total.
     */
    public function list(
        int $limit = 10,
        int $page = 0,
        string $sortBy = 'created_at',
        string $orderBy = 'desc',
    ): array {
        if ($limit < 1 || $limit > 100) {
            throw new PayzumException('limit must be between 1 and 100.');
        }
        if ($page < 0) {
            throw new PayzumException('page is zero-based and cannot be negative.');
        }
        // The server silently falls back to created_at for anything it does not
        // recognise, which turns a typo into quietly wrong ordering.
        if (!in_array($sortBy, ['created_at', 'updated_at'], true)) {
            throw new PayzumException('sortBy must be "created_at" or "updated_at".');
        }
        if (!in_array($orderBy, ['asc', 'desc'], true)) {
            throw new PayzumException('orderBy must be "asc" or "desc".');
        }

        return $this->client->request('GET', '/v1/payment', [
            'limit' => $limit,
            'page' => $page,
            'sortBy' => $sortBy,
            'orderBy' => $orderBy,
        ]);
    }
}
