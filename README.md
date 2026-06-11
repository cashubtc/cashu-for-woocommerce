# Cashu for WooCommerce

![GitHub Workflow Status](https://img.shields.io/github/actions/workflow/status/cashubtc/cashu-for-woocommerce/pr-build.yml)
![GitHub issues](https://img.shields.io/github/issues/cashubtc/cashu-for-woocommerce)
![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/cashu-for-woocommerce)
![WordPress Plugin: Tested WP Version](https://img.shields.io/wordpress/plugin/wp-version/cashu-for-woocommerce)
![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/cashu-for-woocommerce)
[![codecov](https://codecov.io/gh/cashubtc/cashu-for-woocommerce/graph/badge.svg)](https://codecov.io/gh/cashubtc/cashu-for-woocommerce)

A secure Cashu payment gateway for your WooCommerce store.

This plugin adds a checkout that accepts Lightning or Cashu ecash, paying out to your bitcoin lightning address. Ecash payments are converted (melted) via your trusted Cashu mint in real time.

## How it works

Checkout shows a single QR code that any of the following can pay:

- **Lightning wallets (BOLT11)** — settle via your trusted Cashu mint, paid out to your Lightning address
- **Cashu wallets (NUT-18)** — proofs post directly to the plugin, which melts them at the trusted mint and pays your Lightning address
- **BIP-321 wallets** — see both options at once and pick whichever they support

Payments flow customer → mint → your Lightning address in real time. The mint never sees your customers; the server never sees your private keys; spot rates come from Coinbase / CoinGecko.

## Privacy

Cashu works like paying with cash. You and your customer still know each other through the WooCommerce order (name, email, shipping) but no bank, card network, or payment processor records the payment. The mint never sees who paid or what they bought; your Lightning provider just sees "the mint paid me."

The mint is the one party you do have to trust: pick one you trust, and you can swap to another any time without breaking your store.

## Try a demo!

[**Open in WordPress Playground →**](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fcashubtc%2Fcashu-for-woocommerce%2Fmain%2Fblueprint.json)

A disposable WordPress + WooCommerce sandbox boots in your browser with the latest release of the plugin installed, the gateway pre-enabled, and sample products seeded. Add a Lightning address and a trusted mint on the Cashu Settings tab, place a test order, scan the QR. No install, nothing touches your machine; the sandbox is wiped when you close the tab.

The demo defaults to the Lightning tab — Cashu wallet (NUT-18) payments rely on a cross-origin POST that Playground's sandbox can't pass through. To test the full Cashu leg, run a full developer install (see [CONTRIBUTING.md](./CONTRIBUTING.md)) or install on your store.

## Install on your store

- **From WordPress.org:** search for *Cashu for WooCommerce* in the plugin directory
- **Latest release:** download `cashu-for-woocommerce.zip` from [GitHub Releases](https://github.com/cashubtc/cashu-for-woocommerce/releases)

Then activate, go to **WooCommerce → Settings → Cashu Settings**, and configure your Lightning address and trusted mint.

PHP 8.3+ required.

## Donations

Cashu is a free and open-source project, supported by donations. If you love this plugin, please consider supporting us in any of the ways below:

- Tip the developer: [donate.cogmentis.com](https://donate.cogmentis.com)
- Donate to OpenCash: [opencash.dev](https://opencash.dev)

## Links

- [Changelog (GitHub Releases)](https://github.com/cashubtc/cashu-for-woocommerce/releases)
- [Issue tracker](https://github.com/cashubtc/cashu-for-woocommerce/issues)
- [Contributing & development setup](./CONTRIBUTING.md)
- [License (MIT)](./license.txt)
