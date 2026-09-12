=== Payzum for Fluent Forms ===
Contributors: payzum
Tags: crypto, stablecoin, payments, fluent forms, usdc
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept crypto & stablecoin payments (USDC, USDT and more, multi-chain) in Fluent Forms — non-custodial, funds settle directly to your own wallet.

== Description ==

Adds Payzum as a payment method for Fluent Forms payment forms (Fluent Forms 6.0+, the version that ships the payments module in the free plugin).

* The buyer picks the crypto method at checkout and is redirected to a hosted checkout page, where they choose the asset and network and send the payment. No wallet data touches your server.
* Crypto confirmation is asynchronous, so the submission is marked as paid from Payzum's signed server-to-server notification, never from the browser return.
* Every notification is verified with HMAC-SHA-512 over the raw request bytes (with a replay window) before a single field of it is read, and the invoice amount and currency are re-checked against the submission before it is marked paid. Redelivered notifications are a no-op, so a form's actions never fire twice.
* Non-custodial: funds settle directly to your own wallet. Payzum never takes custody.

Subscription (recurring) items are not supported — crypto has no card on file to pull from; the plugin declines those forms cleanly rather than charging once and never renewing.

== Installation ==

1. Install and activate Fluent Forms 6.0 or newer.
2. Upload and activate this plugin.
3. Go to Fluent Forms → Global Settings → Payment Settings → Payzum, enable it and paste your API key and webhook secret (from merchant.payzum.com).
4. Add a payment field to a form and enable the Payzum payment method.

There is nothing to configure in the Payzum dashboard: the notification URL is sent with every invoice.

== Changelog ==

= 1.0.0 =
* Initial release.
