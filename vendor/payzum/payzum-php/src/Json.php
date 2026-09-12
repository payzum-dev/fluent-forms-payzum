<?php

declare(strict_types=1);

namespace Payzum;

/**
 * JSON decoding that does not silently round money.
 *
 * The payments surface returns monetary fields as JSON numbers, frozen for
 * NowPayments compatibility. PHP's json_decode turns those into float, and
 * JSON_BIGINT_AS_STRING does not help — it only covers large integers. So
 * 0.123456789012345678 comes back as 0.12345678901234568: digits gone, with no
 * error and no warning.
 *
 * decodeLossless() walks the raw JSON and quotes every number literal before
 * handing it to json_decode, so numbers arrive as exact decimal strings. The
 * walk has to skip over string contents properly — a naive regex would also
 * quote digits that appear inside a string value, and would trip over escaped
 * quotes.
 *
 * Worth being honest about the ceiling: the gateway itself casts these values
 * to double before serialising, so the digits are already lost upstream. What
 * this buys is that the SDK adds no *further* loss and hands back exactly what
 * the server sent. For genuinely exact amounts, read the buyer surface
 * (GET /v1/invoices/{id}/status), whose amounts are decimal strings end to end.
 */
final class Json
{
    /**
     * Decode JSON with every number preserved as an exact decimal string.
     *
     * @return array<string, mixed>
     * @throws Errors\PayzumException on malformed JSON
     */
    public static function decodeLossless(string $json): array
    {
        $decoded = json_decode(self::quoteNumbers($json), true);

        if (!is_array($decoded)) {
            throw new Errors\PayzumException(
                'Malformed JSON in API response: ' . json_last_error_msg(),
            );
        }

        return $decoded;
    }

    /**
     * Encode a payload, emitting the named fields as JSON numbers without ever
     * turning them into a float.
     *
     * The outbound mirror of decodeLossless, and just as necessary. The API
     * types `price_amount` as a JSON number, so it cannot be sent as a string —
     * but casting "0.123456789012345678" to float to satisfy that produces
     * 0.12345678901234568 on the wire. Which is the same silent rounding this
     * SDK exists to avoid, only on the way out.
     *
     * So: encode with the amount as a string, then strip the quotes around
     * exactly those fields. The value reaches the API as a number with every
     * digit the caller supplied.
     *
     * @param array<string, mixed> $data
     * @param list<string> $numericFields Keys whose string values must land as numbers.
     */
    public static function encodeWithExactNumbers(array $data, array $numericFields): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        foreach ($numericFields as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                continue;
            }
            // Anchored on the encoded key so a value elsewhere cannot match.
            $needle = json_encode($field) . ':"' . $data[$field] . '"';
            $replacement = json_encode($field) . ':' . $data[$field];
            $json = str_replace($needle, $replacement, $json);
        }

        return $json;
    }

    /** Plain decode, for payloads with no monetary fields. */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new Errors\PayzumException(
                'Malformed JSON in API response: ' . json_last_error_msg(),
            );
        }

        return $decoded;
    }

    /**
     * Wrap every JSON number literal in quotes, leaving string contents alone.
     *
     * Single pass, two states: inside a string or outside it. Inside, the only
     * thing that matters is honouring backslash escapes so an escaped quote
     * does not look like the end of the string.
     */
    private static function quoteNumbers(string $json): string
    {
        $out = '';
        $len = strlen($json);
        $inString = false;
        $i = 0;

        while ($i < $len) {
            $c = $json[$i];

            if ($inString) {
                $out .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    // Copy the escaped character verbatim; it can be a quote.
                    $out .= $json[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === '"') {
                    $inString = false;
                }
                $i++;
                continue;
            }

            if ($c === '"') {
                $inString = true;
                $out .= $c;
                $i++;
                continue;
            }

            // A number literal can only start right after : [ , or whitespace,
            // which is exactly where a '-' or digit is legal outside a string.
            if ($c === '-' || ($c >= '0' && $c <= '9')) {
                $end = self::endOfNumber($json, $i, $len);
                $out .= '"' . substr($json, $i, $end - $i) . '"';
                $i = $end;
                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    /**
     * Index just past the JSON number literal starting at $start.
     *
     * Grammar per RFC 8259: an optional minus, an integer part, an optional
     * fraction, an optional exponent.
     */
    private static function endOfNumber(string $json, int $start, int $len): int
    {
        $i = $start;

        if ($i < $len && $json[$i] === '-') {
            $i++;
        }

        $i = self::skipDigits($json, $i, $len);

        if ($i < $len && $json[$i] === '.') {
            $i = self::skipDigits($json, $i + 1, $len);
        }

        if ($i < $len && ($json[$i] === 'e' || $json[$i] === 'E')) {
            $i++;
            if ($i < $len && ($json[$i] === '+' || $json[$i] === '-')) {
                $i++;
            }
            $i = self::skipDigits($json, $i, $len);
        }

        return $i;
    }

    private static function skipDigits(string $json, int $i, int $len): int
    {
        while ($i < $len && $json[$i] >= '0' && $json[$i] <= '9') {
            $i++;
        }

        return $i;
    }
}
