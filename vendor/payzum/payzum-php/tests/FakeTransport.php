<?php

declare(strict_types=1);

namespace Payzum\Tests;

use Payzum\Http\Response;
use Payzum\Http\Transport;
use Payzum\Http\TransportException;

/**
 * Scripted transport: hands back queued responses and records what was asked.
 *
 * Lets the retry and idempotency rules be tested exactly — including that a
 * call which must NOT be retried really is attempted only once.
 */
final class FakeTransport implements Transport
{
    /** @var list<Response|TransportException> */
    private array $queue;

    /** @var list<array{method: string, url: string, headers: array<string,string>, body: ?string}> */
    public array $calls = [];

    /** @param list<Response|TransportException> $queue */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        array $query,
        ?string $body,
        int $timeoutSeconds,
    ): Response {
        $this->calls[] = compact('method', 'url', 'headers', 'body');

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeTransport ran out of scripted responses.');
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** @return array<string, string> */
    public function lastHeaders(): array
    {
        return $this->calls[array_key_last($this->calls)]['headers'] ?? [];
    }
}
