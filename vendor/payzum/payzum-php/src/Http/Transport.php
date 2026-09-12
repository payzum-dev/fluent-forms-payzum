<?php

declare(strict_types=1);

namespace Payzum\Http;

/**
 * The seam between the SDK and the network.
 *
 * Exists so the client can be tested without sockets and so a host application
 * can supply its own HTTP stack (Guzzle, Symfony, a Workers fetch shim) without
 * this package taking a dependency on any of them. A payment SDK with zero
 * runtime dependencies is a smaller supply-chain surface, which matters more
 * here than the convenience of a fluent HTTP library.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string|int> $query
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        array $query,
        ?string $body,
        int $timeoutSeconds,
    ): Response;
}
