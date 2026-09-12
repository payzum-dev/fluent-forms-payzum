<?php

declare(strict_types=1);

namespace Payzum\Resources;

use Payzum\Client;

/**
 * Supported assets.
 *
 * Always read `currencies_detailed`, never the flat `currencies` array: the
 * flat one loses the chain for native assets, so `eth` appears four times —
 * Arbitrum, Base, Ethereum and Optimism — with no way to tell them apart. The
 * flat array is kept only for backwards compatibility.
 */
final class Currencies
{
    /** @var list<array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Every supported (asset, chain) pair, with contract address, decimals and
     * per-network minimum.
     *
     * Cached for the lifetime of the client: the catalogue changes rarely and
     * this endpoint is often called once per checkout render.
     *
     * @return list<array<string, mixed>>
     */
    public function list(bool $refresh = false): array
    {
        if ($this->cache !== null && !$refresh) {
            return $this->cache;
        }

        $body = $this->client->request('GET', '/v1/currencies', authenticated: false);
        $detailed = $body['currencies_detailed'] ?? [];

        return $this->cache = is_array($detailed) ? array_values($detailed) : [];
    }

    /**
     * Look up one asset by its API code, e.g. `usdcmatic`.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $code, bool $refresh = false): ?array
    {
        foreach ($this->list($refresh) as $currency) {
            if (($currency['code'] ?? null) === $code) {
                return $currency;
            }
        }

        return null;
    }

    /**
     * Assets available on one chain, e.g. `polygon`.
     *
     * @return list<array<string, mixed>>
     */
    public function onChain(string $chain, bool $refresh = false): array
    {
        return array_values(array_filter(
            $this->list($refresh),
            static fn (array $c): bool => ($c['chain'] ?? null) === $chain,
        ));
    }
}
