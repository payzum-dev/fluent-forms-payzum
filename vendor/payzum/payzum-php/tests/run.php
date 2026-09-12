<?php

declare(strict_types=1);

/**
 * Test runner with no third-party dependencies.
 *
 * PHPUnit will replace this once the package has real dev-deps, but a suite
 * that runs with nothing but `php tests/run.php` means the vectors can be
 * checked on any machine, in any CI image, from day one — including one that
 * has no network to install anything.
 *
 * Run: php tests/run.php   (or: composer test)
 */

require __DIR__ . '/../vendor/autoload.php';

use Payzum\Errors\ErrorCode;
use Payzum\Errors\SignatureException;
use Payzum\Json;
use Payzum\PaymentStatus;
use Payzum\Webhooks\Verifier;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        return;
    }
    $fail++;
    echo "  FAIL  $name" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function section(string $title): void
{
    echo "\n$title\n";
}

// ---------------------------------------------------------------- vectors

section('Webhook signatures vs the shared corpus');

$vectorsPath = __DIR__ . '/fixtures/webhook-signatures.json';
if (!file_exists($vectorsPath)) {
    fwrite(STDERR, "Missing $vectorsPath — copy it from the payzum-openapi repo.\n");
    exit(2);
}

// The fixture is a copy of the corpus in payzum-openapi. A copy that nobody
// checks is a copy that goes stale, which is the exact failure this whole line
// of work started from — so when the sibling repo is checked out, verify they
// still match. In CI the corpus arrives as a pinned dependency and this is a
// no-op.
$upstream = __DIR__ . '/../../payzum-openapi/vectors/webhook-signatures.json';
if (file_exists($upstream)) {
    check(
        'vector fixture matches payzum-openapi',
        hash_file('sha256', $upstream) === hash_file('sha256', $vectorsPath),
        'copy is stale — refresh it from the payzum-openapi repo',
    );
}

$corpus = json_decode((string) file_get_contents($vectorsPath), true);
$verifier = new Verifier($corpus['secret'], $corpus['replay_window_seconds']);

// A fixed clock so freshness never depends on when the suite runs.
$NOW = 1788000000;

$methods = [
    'payment_ipn' => ['verifyPaymentIpn', 'x-nowpayments-sig'],
    'coinpayments_ipn' => ['verifyCoinPaymentsIpn', 'HMAC'],
    'mass_payout' => ['verifyMassPayout', 'X-Payzum-Signature'],
];

foreach ($corpus['schemes'] as $scheme => $spec) {
    [$method, $header] = $methods[$scheme];

    foreach ($spec['cases'] as $case) {
        $headers = [$header => $case['signature']];
        try {
            $scheme === 'coinpayments_ipn'
                ? $verifier->{$method}($case['body'], $headers)
                : $verifier->{$method}($case['body'], $headers, $NOW);
            $accepted = true;
        } catch (SignatureException) {
            $accepted = false;
        }
        check("$scheme/{$case['name']}", $accepted === $case['valid']);
    }

    foreach ($spec['replay_cases'] as $case) {
        $headers = [$header => $case['signature']];
        try {
            $verifier->{$method}($case['body'], $headers, $case['now']);
            $accepted = true;
        } catch (SignatureException) {
            $accepted = false;
        }
        check("$scheme/replay/{$case['name']}", $accepted === $case['accept']);
    }
}

// ---------------------------------------------- cross-scheme confusion

section('Cross-scheme confusion (the bug that hit 20 of 21 plugins)');

$ipn = $corpus['schemes']['payment_ipn']['cases'][0];

// The mass-payout header must NOT satisfy a payment IPN.
try {
    $verifier->verifyPaymentIpn($ipn['body'], ['X-Payzum-Signature' => $ipn['signature']], $NOW);
    check('mass-payout header rejected for payment IPN', false, 'it was accepted');
} catch (SignatureException $e) {
    check(
        'mass-payout header rejected for payment IPN',
        $e->reason === SignatureException::REASON_MISSING_HEADER,
        "reason was {$e->reason}",
    );
}

// A SHA-256 signature must not satisfy the SHA-512 verifier.
$mp = $corpus['schemes']['mass_payout']['cases'][0];
try {
    $verifier->verifyPaymentIpn($mp['body'], ['x-nowpayments-sig' => $mp['signature']], $NOW);
    check('SHA-256 signature rejected by SHA-512 verifier', false, 'it was accepted');
} catch (SignatureException) {
    check('SHA-256 signature rejected by SHA-512 verifier', true);
}

// ---------------------------------------------- header handling

section('Header lookup');

foreach (['X-NowPayments-Sig', 'x-nowpayments-sig', 'HTTP_X_NOWPAYMENTS_SIG'] as $variant) {
    try {
        $verifier->verifyPaymentIpn($ipn['body'], [$variant => $ipn['signature']], $NOW);
        check("case/format variant accepted: $variant", true);
    } catch (SignatureException $e) {
        check("case/format variant accepted: $variant", false, $e->reason);
    }
}

check(
    'event id read case-insensitively',
    $verifier->eventId(['X-Payzum-Event-Id' => 'pzie_abc']) === 'pzie_abc',
);

check(
    'all five IPN event types are typed, including the two security ones',
    count(Verifier::IPN_EVENT_TYPES) === 5
        && in_array('wrong_token_received', Verifier::IPN_EVENT_TYPES, true)
        && in_array('suspicious_token_received', Verifier::IPN_EVENT_TYPES, true)
        && !in_array('invoice.partial', Verifier::IPN_EVENT_TYPES, true),
);

// ---------------------------------------------- decimals

section('Money keeps its digits');

$exact = '0.123456789012345678';
$decoded = Json::decodeLossless('{"pay_amount":' . $exact . '}');
check('18 decimals survive decoding', $decoded['pay_amount'] === $exact, var_export($decoded['pay_amount'], true));
check('float would have lost them', (string) (float) $exact !== $exact);

$tricky = Json::decodeLossless('{"order_id":"ORDER-12345","desc":"say \"42\" now","amt":1.5}');
check('digits inside strings untouched', $tricky['order_id'] === 'ORDER-12345');
check('escaped quotes survive', $tricky['desc'] === 'say "42" now');
check('number after a tricky string still exact', $tricky['amt'] === '1.5');

// ---------------------------------------------- statuses

section('Status mapping');

check('merchant finished', PaymentStatus::fromMerchant('finished') === PaymentStatus::Finished);
check('buyer paid -> finished', PaymentStatus::fromBuyer('paid') === PaymentStatus::Finished);
check('buyer overpaid -> finished', PaymentStatus::fromBuyer('overpaid') === PaymentStatus::Finished);
check('buyer cancelled -> failed', PaymentStatus::fromBuyer('cancelled') === PaymentStatus::Failed);
check('buyer pending -> waiting', PaymentStatus::fromBuyer('pending') === PaymentStatus::Waiting);
check('finished is terminal', PaymentStatus::Finished->isTerminal());
check('waiting is not terminal', !PaymentStatus::Waiting->isTerminal());
check('only finished counts as paid', PaymentStatus::Finished->isPaid() && !PaymentStatus::PartiallyPaid->isPaid());

try {
    PaymentStatus::fromMerchant('unconfirmed');
    check('unconfirmed rejected (it does not exist)', false, 'it was accepted');
} catch (\Payzum\Errors\PayzumException) {
    check('unconfirmed rejected (it does not exist)', true);
}

// ---------------------------------------------- errors

section('Error codes');

check('16 codes defined', count(ErrorCode::cases()) === 16, (string) count(ErrorCode::cases()));
check('rate limit is retryable', ErrorCode::RateLimitExceeded->isRetryable());
check('internal error is retryable', ErrorCode::InternalError->isRetryable());
check('rate provider down is retryable', ErrorCode::RateProviderDown->isRetryable());
check('quota exceeded is NOT retryable despite being a 429', !ErrorCode::QuotaExceeded->isRetryable());
check('payment not found is not retryable', !ErrorCode::PaymentNotFound->isRetryable());

$retryable = array_filter(ErrorCode::cases(), fn (ErrorCode $c) => $c->isRetryable());
check('exactly three retryable codes', count($retryable) === 3, (string) count($retryable));

// ---------------------------------------------- client behaviour

section('Client: retries, idempotency, error mapping');

$KEY = str_repeat('a', 64);
$slept = [];
$noSleep = function (int $s) use (&$slept): void { $slept[] = $s; };

$mkClient = function (array $queue) use ($KEY, $noSleep): array {
    $t = new \Payzum\Tests\FakeTransport($queue);
    return [new \Payzum\Client(new \Payzum\Config($KEY), $t, $noSleep), $t];
};
$json = fn (int $s, array $b, array $h = []) => new \Payzum\Http\Response($s, json_encode($b), $h);
$err = fn (int $s, string $code, array $h = []) => $json($s, ['statusCode' => $s, 'code' => $code, 'message' => 'x'], $h);

// A retryable error is retried and then succeeds.
[$c, $t] = $mkClient([$err(500, 'INTERNAL_ERROR'), $json(200, ['ok' => true])]);
$c->request('GET', '/v1/payment');
check('retries INTERNAL_ERROR then succeeds', $t->callCount() === 2, "calls={$t->callCount()}");

// A 429 that is QUOTA_EXCEEDED must not be retried, despite being a 429.
[$c, $t] = $mkClient([$err(429, 'QUOTA_EXCEEDED')]);
try {
    $c->request('GET', '/v1/payment');
    check('QUOTA_EXCEEDED not retried', false, 'no exception');
} catch (\Payzum\Errors\ApiException $e) {
    check('QUOTA_EXCEEDED not retried', $t->callCount() === 1 && !$e->isRetryable(), "calls={$t->callCount()}");
}

// Retry-After is honoured over exponential backoff.
$slept = [];
[$c, $t] = $mkClient([$err(429, 'RATE_LIMIT_EXCEEDED', ['retry-after' => '60']), $json(200, [])]);
$c->request('GET', '/v1/payment');
check('honours Retry-After', $slept !== [] && $slept[0] >= 60, 'slept=' . json_encode($slept));

// Invoice creation WITHOUT an idempotency key is never retried.
[$c, $t] = $mkClient([$err(500, 'INTERNAL_ERROR')]);
try {
    $c->createPayment(['price_amount' => 1]);
    check('create without key is not retried', false, 'no exception');
} catch (\Payzum\Errors\ApiException) {
    check('create without key is not retried', $t->callCount() === 1, "calls={$t->callCount()}");
}

// With a key it may retry, and the key is actually sent. The retry must also
// wait out the idempotency consistency window (~60s): inside it the API's
// dedup may not have converged, and a retry can create a second real invoice.
$slept = [];
[$c, $t] = $mkClient([$err(500, 'INTERNAL_ERROR'), $json(201, ['payment_id' => 'pzi_x'])]);
$c->createPayment(['price_amount' => 1], 'order-12345');
check('create with key retries', $t->callCount() === 2, "calls={$t->callCount()}");
check('Idempotency-Key header sent', ($t->calls[0]['headers']['Idempotency-Key'] ?? null) === 'order-12345');
check(
    'create retry waits out the consistency window',
    $slept !== [] && $slept[0] >= \Payzum\Client::consistencyWindowSeconds(),
    'slept=' . json_encode($slept),
);

// A transport failure on create is not retried either — the invoice may exist.
[$c, $t] = $mkClient([new \Payzum\Http\TransportException('timeout')]);
try {
    $c->createPayment(['price_amount' => 1]);
    check('transport failure on create is not retried', false, 'no exception');
} catch (\Payzum\Http\TransportException) {
    check('transport failure on create is not retried', $t->callCount() === 1, "calls={$t->callCount()}");
}

// Errors map to typed codes.
[$c, $t] = $mkClient([$err(404, 'PAYMENT_NOT_FOUND')]);
try {
    $c->request('GET', '/v1/payment/nope');
    check('404 maps to PAYMENT_NOT_FOUND', false);
} catch (\Payzum\Errors\ApiException $e) {
    check('404 maps to PAYMENT_NOT_FOUND', $e->errorCode === ErrorCode::PaymentNotFound && $e->statusCode === 404);
}

// Money survives the client, not just the decoder.
[$c, $t] = $mkClient([new \Payzum\Http\Response(200, '{"pay_amount":0.123456789012345678}')]);
$body = $c->request('GET', '/v1/payment/x');
check('client preserves decimals end to end', $body['pay_amount'] === '0.123456789012345678', var_export($body['pay_amount'], true));

// Outbound amounts must not be rounded either. price_amount has to reach the
// wire as a JSON number, but casting the string to float to get there is
// exactly the silent rounding this SDK exists to prevent.
[$c, $t] = $mkClient([$json(201, ['payment_id' => 'pzi_x'])]);
$payzumOut = new \Payzum\Payzum($KEY, \Payzum\Payzum::BASE_URL, $t);
$payzumOut->payments->create('0.123456789012345678', 'usd', 'usdcmatic');
$sent = $t->calls[0]['body'];
check(
    'outbound amount keeps all 18 digits',
    str_contains($sent, '"price_amount":0.123456789012345678'),
    $sent,
);
check('outbound amount is a JSON number, not a string', !str_contains($sent, '"price_amount":"'));

[$c, $t] = $mkClient([$json(201, [])]);
$payzumOut = new \Payzum\Payzum($KEY, \Payzum\Payzum::BASE_URL, $t);
$payzumOut->payments->create('49.99', 'usd', 'usdcmatic', orderId: 'ORDER-49.99');
check(
    'only the named field is unquoted, not a lookalike value elsewhere',
    str_contains($t->calls[0]['body'], '"order_id":"ORDER-49.99"'),
    $t->calls[0]['body'],
);

// The api key travels in the right header, and only when authenticated.
[$c, $t] = $mkClient([$json(200, [])]);
$c->request('GET', '/v1/currencies', authenticated: false);
check('no api key on public endpoints', !isset($t->lastHeaders()['x-api-key']));

[$c, $t] = $mkClient([$json(200, [])]);
$c->request('GET', '/v1/payment');
check('api key sent on authenticated endpoints', ($t->lastHeaders()['x-api-key'] ?? null) === $KEY);

// Config guards.
try {
    new \Payzum\Config('too-short');
    check('short api key rejected', false);
} catch (\Payzum\Errors\PayzumException) {
    check('short api key rejected', true);
}
check('32-char key accepted (server rule, not 64-hex)', (new \Payzum\Config(str_repeat('k', 32)))->apiKey !== '');

try {
    new \Payzum\Config($KEY, 'http://merchant.payzum.com');
    check('plain http rejected', false);
} catch (\Payzum\Errors\PayzumException) {
    check('plain http rejected', true);
}

// ---------------------------------------------- resource guards

section('Resource-level validation');

$payzum = new \Payzum\Payzum($KEY, \Payzum\Payzum::BASE_URL, new \Payzum\Tests\FakeTransport([]));

try {
    $payzum->payments->create('49.99', 'usd', 'all', pricingMode: 'direct');
    check('direct + all rejected locally', false);
} catch (\Payzum\Errors\PayzumException) {
    check('direct + all rejected locally', true);
}

try {
    $payzum->payments->list(sortBy: 'nonsense');
    check('bad sortBy rejected (server would silently ignore it)', false);
} catch (\Payzum\Errors\PayzumException) {
    check('bad sortBy rejected (server would silently ignore it)', true);
}

try {
    $payzum->invoices->status('not-an-id');
    check('malformed invoice id rejected locally', false);
} catch (\Payzum\Errors\PayzumException) {
    check('malformed invoice id rejected locally', true);
}

$t = new \Payzum\Tests\FakeTransport([$json(200, ['status' => 'ok'])]);
$payzum = new \Payzum\Payzum($KEY, \Payzum\Payzum::BASE_URL, $t);
$body = $payzum->health();
check(
    'health hits /v1/status unauthenticated',
    ($body['status'] ?? null) === 'ok'
        && str_ends_with($t->calls[0]['url'], '/v1/status')
        && !isset($t->calls[0]['headers']['x-api-key']),
);

// ---------------------------------------------- result

echo "\n" . str_repeat('-', 52) . "\n";
echo sprintf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
