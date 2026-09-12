# Payzum for Fluent Forms — Accept Crypto & Stablecoin Payments (USDC, USDT)

Accept **cryptocurrency and stablecoin payments** (USDC, USDT and more, multi-chain) in
[Fluent Forms](https://fluentforms.com) payment forms through
[Payzum](https://payzum.com) — **non-custodial**: funds settle directly to your
own wallet, Payzum never takes custody. Works with the **free** Fluent Forms —
no Pro required. No chargebacks, no card networks, no PCI surface.

- **Plugin:** `payzum-for-fluent-forms` · **Version:** 1.0.0 · **License:** MIT
- **Requires:** WordPress ≥ 6.0, Fluent Forms 6.0+ (ships the payments module), PHP ≥ 8.1

## How it works

1. The visitor submits a payment form with **Payzum** selected and is
   redirected to a hosted checkout page (QR code + deposit address, live
   status), where they choose the coin and chain and send the payment. No
   wallet or card data touches your server.
2. Crypto confirmation is **asynchronous**, so the submission is marked paid
   from Payzum's signed server-to-server IPN webhook, never from the browser
   return — a closed tab never loses a paid submission.
3. Every webhook is verified with **HMAC-SHA-512 over the raw request bytes**
   (constant-time compare, replay window) before a single field of it is read.
   Redelivered webhooks are deduplicated, the settled amount and currency are
   re-checked against the transaction, and the whole transition runs under a
   per-submission lock — a form's confirmations and notifications fire exactly
   once.

## Features

- **Stablecoin-first**: USDC and USDT across multiple chains (Polygon, Ethereum,
  Arbitrum, Base, Optimism, Tron, Solana and more), plus major cryptocurrencies.
- **Non-custodial** — payments settle to the site owner's own wallet.
- **Works with free Fluent Forms** — uses the built-in payments module and the
  standard redirect flow; no Pro licence, no forked code.
- **Signed IPN webhooks** (HMAC-SHA-512) settle submissions server-side, with
  amount/currency verification, event deduplication and per-submission locking.
- **Secrets encrypted at rest** with Fluent Forms' own key encryption, masked
  in the admin.
- **Zero chargebacks** — crypto payments are final.

## Installation

Upload the `payzum-for-fluent-forms` folder to `/wp-content/plugins/` (or
install the zip via **Plugins → Add New → Upload**), then activate it
(requires Fluent Forms). The official
[`payzum/payzum-php`](https://packagist.org/packages/payzum/payzum-php) SDK is
vendored, so no composer step is needed.

## Configuration

Go to **Fluent Forms → Global Settings → Payment Settings → Payzum**:

| Setting | Meaning |
|---|---|
| API key | From your [Payzum merchant dashboard](https://merchant.payzum.com) |
| Webhook secret | Verifies incoming payment webhooks (IPN); stored encrypted |
| Environment | Production or staging (staging needs its own API key) |

Paste the shown **IPN URL**
(`https://<your-site>/?fluentform_payment_api_notify=1&payment_method=payzum`)
into the Payzum dashboard webhook settings. The signature header is fixed;
nothing to configure.

Then add a **Payment Method** field to any payment form and enable Payzum.
The payer chooses the coin on the Payzum checkout, limited to the allowlist
you configure in the Payzum dashboard (**Merchants → Settings → Accepted
tokens**) and enforced server-side.

## Payment status mapping

| Payzum payment status | Fluent Forms transaction / submission |
|---|---|
| `finished` (amount and currency match) | Paid — confirmations and notifications fire once |
| `finished` (settled amount or currency does not match) | Stays pending, flagged in the log for review |
| `partially_paid` | No change (underpaid — must not fulfil) |
| `expired` / `failed` | Failed |
| anything else | Acknowledged, no state change |

Subscriptions are declined at submission time: crypto has no recurring pull,
so only one-time payment forms are supported.

## FAQ

**Is Payzum custodial?**
No. Funds settle directly to your own wallet — Payzum never holds your money.

**Can Fluent Forms accept USDT or USDC payments?**
Yes — with this plugin, any Fluent Forms payment form can take USDC, USDT or
other assets on the chains you allow, settled automatically from a signed
webhook.

**Do payers need an account or a specific wallet?**
No. They scan a QR or copy a deposit address from the Payzum checkout and pay
from any wallet.

**Does it require Fluent Forms Pro?**
No. It runs on the free Fluent Forms 6.0+, which ships the payments module.

**What about chargebacks?**
There are none — crypto payments are final, which eliminates chargeback fraud.

**What data is shared with Payzum?**
Only the payment amount, currency, a submission reference and your site's
callback URLs — no personal data from the form. Endpoints:
`https://merchant.payzum.com` (production), `https://staging.payzum.com`
(staging).

## Related Payzum integrations

Payzum ships official plugins for most major e-commerce, donation and billing
platforms — WooCommerce, Easy Digital Downloads, GiveWP, Paid Memberships Pro,
Tutor LMS, Magento 2, PrestaShop, Shopware 6, OpenCart, Zen Cart, nopCommerce,
Ecwid, BigCommerce, Shopify, Wix, Medusa, Vendure, Saleor, Sylius, WHMCS,
Blesta, HostBill, ClientExec, pretix, Frappe/ERPNext, Akaunting and
django-payments — plus official SDKs for PHP, Node.js/TypeScript, Python and
Rust. Browse them all at [github.com/payzum-dev](https://github.com/payzum-dev).

## About Payzum

[Payzum](https://payzum.com) is a non-custodial crypto payment gateway for
merchants: accept USDC, USDT and other digital assets with settlement straight
to your own wallet, optional auto-conversion to stablecoins, and a single REST
API. API docs: [merchant.payzum.com/api/docs](https://merchant.payzum.com/api/docs).

## License

[MIT](LICENSE). Contributed and maintained by Payzum.
