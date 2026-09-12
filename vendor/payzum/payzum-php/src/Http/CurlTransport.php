<?php

declare(strict_types=1);

namespace Payzum\Http;

use Payzum\Errors\PayzumException;

/** Default transport: ext-curl, which ships with essentially every PHP install. */
final class CurlTransport implements Transport
{
    public function send(
        string $method,
        string $url,
        array $headers,
        array $query,
        ?string $body,
        int $timeoutSeconds,
    ): Response {
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new PayzumException('Could not initialise curl.');
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            // Never follow redirects: a redirect on an authenticated API call
            // would replay the api key at whatever host the redirect names.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $k, string $v): string => "$k: $v",
                array_keys($headers),
                array_values($headers),
            ),
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $len;
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            // Surfaced as a distinct type so the client can decide whether a
            // retry is safe — for invoice creation it usually is not.
            throw new TransportException($error !== '' ? $error : 'curl failed with no message');
        }

        return new Response($status, (string) $raw, $responseHeaders);
    }
}
