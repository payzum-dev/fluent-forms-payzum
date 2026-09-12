<?php

declare(strict_types=1);

namespace Payzum\Resources;

use Payzum\Client;

/**
 * Conversion estimates and network minimums. Neither needs an API key.
 */
final class Rates
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Estimate what a fiat amount comes to in a crypto asset.
     *
     * Note the response mixes types: `estimated_amount` is a decimal string
     * while `amount_from` and `min_amount_usd` are numbers. That is deliberate
     * on the API's side, and it is why monetary fields are marked per field
     * rather than per endpoint.
     *
     * @return array<string, mixed>
     */
    public function estimate(string $amount, string $from, string $to): array
    {
        return $this->client->request(
            method: 'GET',
            path: '/v1/estimate',
            query: ['amount' => $amount, 'currency_from' => $from, 'currency_to' => $to],
            authenticated: false,
        );
    }

    /**
     * Minimum payable amount for a currency pair.
     *
     * Worth calling before creating an invoice: an amount below the network
     * minimum is rejected with AMOUNT_BELOW_MINIMUM, and finding that out after
     * the buyer has already committed is a bad moment to discover it.
     *
     * @return array<string, mixed>
     */
    public function minAmount(string $from, string $to): array
    {
        return $this->client->request(
            method: 'GET',
            path: '/v1/min-amount',
            query: ['currency_from' => $from, 'currency_to' => $to],
            authenticated: false,
        );
    }
}
