=== Cashu For WooCommerce ===
Contributors: robwoodgate
Donate link: https://donate.cogmentis.com/
Tags: payments, bitcoin, lightning, checkout, cashu
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.2.0
License: MIT
License URI: https://github.com/robwoodgate/cashu-for-woocommerce/blob/main/license.txt

Accept Cashu ecash and Lightning payments in WooCommerce, automatically melted to your Bitcoin lightning address.

== Description ==

Cashu For WooCommerce adds a secure Cashu payment gateway to your WooCommerce store.

It allows you to receive private bitcoin payments using Cashu ecash, which is automatically converted (melted) and sent to your bitcoin lightning address. It also accepts lightning payments from regular bitcoin wallets.

Checkout presents a single QR code that any of the following can scan and pay:

- Lightning wallets: pay via the trusted mint and out to your lightning address.
- Cashu wallets (NUT-18): post proofs directly to the plugin, which melts them at the trusted mint and pays your lightning address.
- Wallets that understand BIP-321 unified URIs see both options at once and pick whichever they support.

==  Features ==

- Receive to any lightning address: Your lightning provider doesn't need any special features.
- Privacy, just like cash: All payments are routed through your trusted Cashu mint. Your lightning address stays private, so do your customer's payments.
- Flexibility: switch trusted mints at any time to find the best fees and service.
- Pay via Lightning: Customers can pay from a regular bitcoin lightning wallet.
- Pay via Cashu (NUT-18): Customer wallets handle their own input fees, so the headline price is exactly what the merchant receives.
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

Payments in Cashu ecash are melted to bitcoin and send via lightning in real time, so no sensitive keys are required on the server.

== Changelog ==

= 0.2.0 =
New: choose which payment tabs (Unified / Cashu / Lightning) appear at checkout, and which is the default.
New: settings probe the configured mint on save to verify it supports BOLT11 sat for mint and melt.
Improved: per-tab QR centre icons; uppercase LIGHTNING QR for broader wallet compatibility.
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
