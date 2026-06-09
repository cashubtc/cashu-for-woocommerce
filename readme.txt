=== Cashu For WooCommerce ===
Contributors: robwoodgate
Donate link: https://opencash.dev/
Tags: payments, bitcoin, lightning, checkout, cashu
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.3.0
License: MIT
License URI: https://github.com/cashubtc/cashu-for-woocommerce/blob/main/license.txt

Accept Cashu ecash and Lightning payments, automatically melted to your Bitcoin lightning address.

== Description ==

A secure Cashu payment gateway for your WooCommerce store.

This plugin adds a checkout that accepts Lightning or Cashu ecash, paying out to your bitcoin lightning address. Ecash payments are converted (melted) via your trusted Cashu mint in real time.

Checkout presents a single QR code that any of the following can scan and pay:

- Lightning wallets: pay via the trusted mint and out to your lightning address.
- Cashu wallets (NUT-18): post proofs directly to the plugin, which melts them at the trusted mint and pays your lightning address.
- Wallets that understand BIP-321 unified URIs see both options at once and pick whichever they support.

==  Features ==

- Receive to any lightning address: Your lightning provider doesn't need any special features.
- Privacy, just like cash: All payments are routed through your trusted Cashu mint. Your lightning address stays private, so do your customer's payments.
- Flexibility: switch trusted mints at any time to find the best fees and service.
- Pay via Lightning: Customers can pay from a regular bitcoin lightning wallet.
- Pay via Cashu: Customers can send Cashu ecash direct from any Cashu wallet.
- Wallet-agnostic QR: Unified BIP-321 QR by default, with Cashu-only and Lightning-only fallback tabs for older wallets.
- Safety: You only have to trust one Cashu mint (your trusted mint).
- Accurate prices: Spot rates are taken from coinbase / coingecko.
- I18n: Checkout can be translated into any language.

== Installation ==

1. Make sure your server is running PHP 8.3 or higher
2. Upload and unzip `cashu-for-woocommerce.zip` to the `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Configure the plugin settings via the WooCommerce settings page.
5. Ensure you setup your lightning address so Cashu payments can be routed properly.

== Frequently Asked Questions ==

= Does this store private keys in WordPress? =

No, the plugin requires only your public lightning address.

Payments in Cashu ecash are melted to bitcoin and sent to you via lightning in real time, so no sensitive keys are required on the server.

== External services ==

This plugin connects to several third-party services to function. Each is described below: what it does, what data is sent, when, and links to the relevant terms.

= Cashu mint (merchant-configured) =

The mint is chosen by the merchant in plugin settings. It is used to mint Lightning invoices for customers paying via Lightning, and to melt Cashu ecash proofs received from customers into a Lightning payment to the merchant's Lightning address.

- What is sent: Lightning quote/melt requests, Cashu ecash proofs (only when a customer pays in ecash), and the merchant's Lightning address. No personally identifying customer data is sent.
- When: only during the active checkout flow (quoting, payment, settlement).
- Terms of service and privacy policy depend on the mint configured by the merchant. Merchants should review the terms of their chosen mint before using it.

= Lightning address provider (merchant-configured) =

The plugin resolves the merchant's Lightning address (an LNURL-pay endpoint) to obtain a payable invoice. The endpoint is determined entirely by the Lightning address the merchant enters in plugin settings.

- What is sent: the requested invoice amount and the username part of the merchant's Lightning address.
- When: during checkout, when a payment quote is being generated.
- Terms of service and privacy policy depend on the Lightning provider hosting the merchant's address.

= Coinbase Spot Price API =

Used to fetch the current Bitcoin spot price in the store's configured currency, so customers can see fiat-equivalent amounts at checkout.

- What is sent: only the store's fiat currency code (e.g. "GBP", "USD") in the URL path. No customer or store-identifying data is sent.
- When: when a checkout payment quote is created. Results are cached for 30 seconds.
- Endpoint: https://api.coinbase.com/v2/prices/BTC-{CURRENCY}/spot
- Terms of service: https://www.coinbase.com/legal/user_agreement
- Privacy policy: https://www.coinbase.com/legal/privacy

= CoinGecko Simple Price API =

Used as an alternative source for Bitcoin fiat pricing.

- What is sent: only the store's fiat currency code as a query parameter. No customer or store-identifying data is sent.
- When: when a checkout payment quote is created. Results are cached for 30 seconds.
- Endpoint: https://api.coingecko.com/api/v3/simple/price
- Terms of service: https://www.coingecko.com/en/terms
- Privacy policy: https://www.coingecko.com/en/privacy

== Source ==

Development happens on [GitHub](https://github.com/cashubtc/cashu-for-woocommerce). Issues and pull requests are welcome.

== Donations ==

Cashu is a free and open-source project, supported by donations. If you love this plugin, please consider supporting us in any of the ways below:

- Tip the developer: https://donate.cogmentis.com
- Donate to OpenCash: https://opencash.dev

== Changelog ==

= 0.3.0 =
Changed: plugin now lives in the official cashubtc GitHub organisation.
Changed: wp.org Donate link points to OpenCash; the developer tip jar remains listed in the Donations section.
Changed: in-admin review reminder links to the wp.org reviews page.
Maintenance: removed bundled translations and local POT/PO/MO machinery; translate.wordpress.org now handles translations.

= 0.2.0 =
New: choose which payment tabs (Unified / Cashu / Lightning) appear at checkout, and which is the default.
New: settings probe the configured mint on save to verify it supports lightning (BOLT11) sat for mint and melt.
Improved: per-tab QR centre icons.
Improved: stranded ecash proofs can self-recover from local storage on page refresh.
Fixed: already-paid quotes no longer trigger the recovery UI when refreshing the checkout.
Fixed: payment success is shown visually before redirect to the thank-you page.

= 0.1.1 =
Adds comment to LN invoice (if supported)
Tweaks total / fee display

= 0.1.0 =
First public release. Test carefully, don't be reckless.

== Upgrade Notice ==

= 0.1.0 =
First public release. Test carefully, don't be reckless.
