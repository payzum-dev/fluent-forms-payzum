# payzum-php

Official PHP SDK for [Payzum](https://payzum.com) — accept stablecoin and crypto
payments, and verify IPN webhooks.

```bash
composer require payzum/payzum-php
```

Zero runtime dependencies. PHP 8.1+.

## Quick start

```php
use Payzum\Payzum;

$payzum = new Payzum($_ENV['PAYZUM_API_KEY']);

$invoice = $payzum->payments->create(
    priceAmount: '49.99',
    priceCurrency: 'usd',
    payCurrency: 'all',          // let the buyer pick the asset
    orderId: 'ORDER-12345',
);

header('Location: ' . $invoice['invoice_url']);
```

Then, in your webhook endpoint — against the **raw** body, before any parsing:

```php
$payload = $payzum->webhooks($_ENV['PAYZUM_WEBHOOK_SECRET'])
    ->verifyPaymentIpn(file_get_contents('php://input'), getallheaders());

if (Payzum\PaymentStatus::fromMerchant($payload['payment_status'])->isPaid()) {
    fulfil($payload['order_id']);
}
```

Verification throws on failure rather than returning a boolean, so it cannot be
ignored by accident.

## What it handles for you

**The three webhook signature schemes.** They are not interchangeable, and
mixing them is the most expensive mistake available with this API:

| Webhook | Algorithm | Header |
|---|---|---|
| Payment IPN | HMAC-SHA-512 | `x-nowpayments-sig` |
| Payment IPN, CoinPayments-mode merchants | HMAC-SHA-512, form-encoded body | `HMAC` |
| Mass payout | HMAC-SHA-256 | `X-Payzum-Signature` |

Payzum ships 21 cart plugins; **20 of them read the mass-payout header for a
payment IPN**. The signature never verifies, deliveries 401, the gateway retries
five times and dead-letters, and the order is silently never fulfilled. The SDK
owns the header names so that class of bug cannot recur — they are not
configurable, and lookup is case-insensitive.

Verification also covers the replay window (10 minutes against the signed
`event_at`) and exposes the event id to deduplicate on. For CoinPayments-mode
deliveries there is no window, because that payload carries no timestamp at all
— deduplicate on `ipn_id`.

**Money that keeps its digits.** The payments surface returns amounts as JSON
numbers, and `json_decode` turns `0.123456789012345678` into
`0.12345678901234568` with no error. The SDK decodes losslessly and hands back
exact decimal strings. Worth knowing the ceiling, though: the gateway itself
casts to double before serialising, so those digits are already gone upstream.
What this buys is that the SDK adds no *further* loss. When you need genuinely
exact amounts, read `$payzum->invoices->status()` — the buyer surface uses
decimal strings end to end.

**One status vocabulary.** The merchant surface emits five values and the buyer
surface six, with no name in common. `PaymentStatus` maps both.

There is no `overpaid` on the merchant surface: overpayment is handled
internally and resolved case by case by support, so the merchant is simply told
"paid". `unconfirmed` does not exist either, despite older documentation.

**Retries that will not double-charge.** Three of the sixteen error codes are
retryable. `QUOTA_EXCEEDED` is not, despite arriving as a 429 — it means too
many invoices are open, so retrying makes it worse.

Invoice creation is **never** retried automatically without an
`Idempotency-Key`, because the API does not enforce `order_id` uniqueness and a
blind retry creates a second real invoice. Pass a key to opt in:

```php
$payzum->payments->create(
    priceAmount: '49.99',
    priceCurrency: 'usd',
    payCurrency: 'usdcmatic',
    orderId: 'ORDER-12345',
    idempotencyKey: 'ORDER-12345',
);
```

**Local validation** that saves a round trip: API key length, invoice id shape,
`pricing_mode: "direct"` against `pay_currency: "all"`, and `sortBy` values the
server would otherwise ignore in silence.

## Other things you can do

```php
// Check the minimum before you commit the buyer to an amount.
$min = $payzum->rates->minAmount('usd', 'usdcmatic');

// Read an invoice by Payzum id or by your own order id.
$invoice = $payzum->payments->get('ORDER-12345');

// Buyer-facing status: no API key, exact decimal amounts.
$status = $payzum->invoices->status('pzi_c8k2m4p6r8t0v2x4z6b8d0f2');

// Supported assets, with the chain of each — the flat list cannot tell you
// which of the four `eth` entries is Arbitrum, Base, Ethereum or Optimism.
$polygon = $payzum->currencies->onChain('polygon');
```

## Sandbox

```php
$payzum = Payzum::sandbox($_ENV['PAYZUM_STAGING_KEY']);
```

Staging has isolated data and its own API keys. Note that `api.payzum.com` does
**not** serve the API — use `merchant.payzum.com`.

## Tests

```bash
composer test
```

No network and no dev dependencies. Webhook verification is checked against the
shared corpus in [`payzum-openapi`](https://github.com/payzum-dev/payzum-openapi),
the same vectors the TypeScript and Python SDKs verify against — including cases
that reproduce the cross-scheme confusion above, so no SDK can ship it.

## Links

- Documentation: <https://merchant.payzum.com/docs>
- Machine-readable reference for AI agents: <https://merchant.payzum.com/llms.txt>

## License

MIT — see [LICENSE](LICENSE).
