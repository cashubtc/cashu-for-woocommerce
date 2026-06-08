# Cashu for WooCommerce

> Accept Bitcoin Lightning and Cashu ecash payments in WooCommerce, automatically melted to your Lightning address. Single-QR checkout, no private keys on the server, swap mints any time.

## Try it now

[**Open in WordPress Playground →**](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Frobwoodgate%2Fcashu-for-woocommerce%2Fmain%2Fblueprint.json)

A disposable WordPress + WooCommerce sandbox boots in your browser with the latest release of the plugin installed, the gateway pre-enabled, and sample products seeded. Add a Lightning address and a trusted mint on the Cashu Settings tab, place a test order, scan the QR. No install, nothing touches your machine; the sandbox is wiped when you close the tab.

## What it does

Checkout shows a single QR code that any of the following can pay:

- **Lightning wallets** — settle via your trusted Cashu mint, paid out to your Lightning address
- **Cashu wallets (NUT-18)** — proofs post directly to the plugin, which melts them at the trusted mint and pays your Lightning address
- **BIP-321 wallets** — see both options at once and pick whichever they support

Payments flow customer → mint → your Lightning address in real time. The mint never sees your customers; the server never sees your private keys; spot rates come from Coinbase / CoinGecko.

## Install

- **From WordPress.org:** search for *Cashu for WooCommerce* in the plugin directory (once approved)
- **Latest release:** download `cashu-for-woocommerce.zip` from [GitHub Releases](https://github.com/robwoodgate/cashu-for-woocommerce/releases)

Then activate, go to **WooCommerce → Settings → Cashu Settings**, and configure your Lightning address and trusted mint.

PHP 8.3+ required.

## Links

- [Changelog (GitHub Releases)](https://github.com/robwoodgate/cashu-for-woocommerce/releases)
- [Issue tracker](https://github.com/robwoodgate/cashu-for-woocommerce/issues)
- [Contributing & development setup](./CONTRIBUTING.md)
- [License (MIT)](./license.txt)
