=== Payzum for Fluent Forms ===
Contributors: payzum
Tags: crypto, stablecoin, payments, fluent forms, usdc
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
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

== External services ==

This plugin connects to the Payzum API to create payment invoices and receive payment
notifications. It is required for the gateway to work.

* What it sends: when a buyer submits a form with a Payzum payment field, the plugin sends the form total, currency, submission/entry id and your site's callback/return URLs to Payzum to create the invoice.
  Payment confirmations arrive as signed webhooks from Payzum; the plugin verifies their
  signature before crediting the payment. No customer personal data is sent by the plugin.
* When: only when the gateway is enabled and a form with a Payzum payment field is submitted (or the site owner tests the
  connection from the settings screen).
* Endpoints: `https://merchant.payzum.com` (production) or `https://staging.payzum.com`
  (staging), as selected in the plugin settings.
* Service provider: Payzum — [terms](https://payzum.com/terms), [privacy](https://payzum.com/privacy).

== Changelog ==

= 1.0.1 =
* The text domain now matches the plugin slug (`payzum-for-fluent-forms`), so translations load.
* The readme declares the external Payzum API service and is tested against WordPress 7.1.

= 1.0.0 =
* Initial release.
