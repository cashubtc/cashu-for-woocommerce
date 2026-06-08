# Cashu For WooCommerce

Thank you for your interest in contributing to Cashu For WooCommerce. This project extends WooCommerce with Cashu payments, so changes need to be stable, secure and easy for other developers to follow.

This guide explains how to set up the development environment, run the tooling, use the wp-env WordPress environment, and prepare changes before opening a pull request.

## Getting started

You will need

* Node and npm for build, i18n and readme
* PHP and Composer for running unit tests
* Docker for the wp-env local WordPress environment
* A local clone of this repository

Optionally, you can also install wp-env either globally or as a dev dependency, so you can run a disposable WordPress and WooCommerce site for testing the gateway.

From your terminal

```bash
cd /path/to/cashu-for-woocommerce
npm install
composer install
```

This installs all the development dependencies, including Prettier, Vite and PHPUnit.

The rest of this file gives details on all the developer tools, but here's a TL;DR:

**TL;DR:**
```bash
# Spin up a WordPress server and WooCommerce store with plugin installed
npm run wp-env:start
npm run wp-env:seed-store

# Reset or tear it down
npm run wp-env:reset
npm run wp-env:destroy

# Prep for release
npm run build

# Build Plugin Zip file
./build.sh
```

## Quick try in WordPress Playground (no setup)

If you just want to click around the plugin without installing Docker or Node, open it in [WordPress Playground](https://wordpress.org/playground/) — an in-browser WP+WC sandbox that boots in seconds:

[**Open Cashu for WooCommerce in Playground →**](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Frobwoodgate%2Fcashu-for-woocommerce%2Fmain%2Fblueprint.json)

The blueprint at `blueprint.json` boots WordPress + WooCommerce, pulls the latest GitHub release zip of this plugin, dismisses the WooCommerce setup wizard, seeds a sample product, auto-logs in as admin, and lands on the Cashu settings tab. The sandbox is wiped when the tab closes — nothing touches your machine. Best for review and exploration; for active development use the wp-env workflow below.

The playground shows the **latest GitHub release** of the plugin, not the current `main` branch. To demo unreleased changes, swap the `url` in `blueprint.json` for a release-candidate zip URL.

## Local WordPress and WooCommerce environment with wp-env

This repository includes a `.wp-env.json` and npm scripts to spin up a complete WordPress site with WooCommerce and the plugin already active.

The environment runs two sites

* Development site at `http://localhost:8888`
* Test site at `http://localhost:3000`

Both are separate WordPress installs backed by separate databases, but they share the same code from this repository.

### Starting the environment

From the plugin root

```bash
npm install
npm run wp-env:start
```

This uses your `.wp-env.json` to

* Download and configure WordPress
* Install WooCommerce and Email Log from the official plugin zips
* Mount this repository as a plugin and activate it
* Load the `one-theme` theme in the development environment

When it finishes you should see something similar to

```text
WordPress development site started at http://localhost:8888
WordPress test site started at http://localhost:3000
```

Log in to the development site at

* URL, [http://localhost:8888/wp-admin](http://localhost:8888/wp-admin)
* User, `admin`
* Password, `password`

The Cashu For WooCommerce plugin and WooCommerce should already be active.

### Seeding a dummy WooCommerce store

To quickly get products into your development store, the `package.json` includes a helper script which uses WooCommerce’s own sample data.

From the plugin root

```bash
npm run wp-env:seed-store
```

This will

* Install and activate the WordPress importer inside the wp-env container
* Import the `sample_products.xml` file that ships with WooCommerce into the development site

After that, you will usually need to activate the Cashu for WooCommerce plugin and set it up:

* Go to WooCommerce, Settings, Payments
* Enable the Cashu ecash plugin

You should now have a working dummy store where you can place test orders through the Cashu gateway straight away.

### Development site vs test site

The development site at `http://localhost:8888` is your playground. This is where you

* Configure WooCommerce and the Cashu gateway
* Change settings, run through the setup wizard, install extra plugins
* Place manual test orders and watch how the gateway behaves

The test site at `http://localhost:8889` uses a separate database and is intended for automated tests or destructive experiments. You can target it from wp-env using the `tests-cli` container if you decide to run tests inside Docker later on.

At the moment, the npm scripts run wp-env commands against the development site via

```bash
wp-env run cli ...
```

so your dummy store data and XML imports all go to `http://localhost:8888`.

### Reading logs

As part of development, you will likely want to view / tail the various logs.

**Apache log:**

The following command will tail the Apache webserver log:

```bash
npx wp-env logs
```

**PHP logs:**

The PHP log is stored in the `debug.log` file in the plugin root folder. You can tail it with:

```bash
tail -f debug.log
```

**WooCommerce logs:**

The plugin logs to the WooCommerce internal log as well. View that at inside WordPress:

```
WordPress Admin > WooCommerce > Status > Logs
```

### Testing the cashu wallet (NUT-18) leg over HTTPS

The Lightning leg of checkout works fine on `http://localhost:8888` because it is exercised entirely from the same browser. The cashu-wallet leg is different — a real mobile (or web) cashu wallet has to POST the proofs back to the plugin's `/cashu-wc/v1/pay/...` endpoint, and:

* Mobile wallets cannot reach `localhost` on your dev machine.
* Most cashu wallets refuse non-HTTPS targets for proofs (proofs are bearer assets).

The simplest fix during development is a Cloudflare quick tunnel: it exposes the wp-env site at an `https://<random>.trycloudflare.com` URL that real wallets will accept, no account or signup required.

#### One-time setup

```bash
brew install cloudflared
```

#### Bring up / tear down

```bash
# wp-env must already be running (npm run wp-env:start)
npm run wp-env:tunnel
```

The script will

* Start a quick tunnel forwarding to `http://localhost:8888`
* Parse the assigned `https://*.trycloudflare.com` URL out of cloudflared's log
* Point `WP_SITEURL` and `WP_HOME` at the tunnel URL (see "wp-env constants" below)
* Print the public URL and block in the foreground

Tear down with **Ctrl-C** in the same terminal — a trap handler kills cloudflared and reverts `WP_SITEURL` / `WP_HOME` back to `http://localhost:8888` so the local site keeps working after.

#### When things get stuck

If the tunnel script dies uncleanly (kill -9, terminal crash, etc), `WP_SITEURL` will still be pointing at a dead `trycloudflare.com` host and the local storefront will 502 or redirect oddly. Reset with

```bash
npm run wp-env:tunnel-reset
```

#### wp-env constants vs DB options

wp-env hardcodes `WP_SITEURL` and `WP_HOME` as PHP constants in `wp-config.php`. In WordPress those constants short-circuit `get_option('siteurl')` / `get_option('home')` before any DB read or filter — so `wp option update siteurl …` will silently no-op. The tunnel script uses `wp config set WP_SITEURL <url> --type=constant` instead, which edits the constant directly. The next `wp-env start` regenerates `wp-config.php` from scratch, so any leftover constant value gets blown away — no permanent state to worry about.

#### Tips while tunneling

* Load the dev browser at the tunnel URL too, not `localhost:8888`. The page itself still loads either way, but the polling `fetch()` and other `rest_url()`-derived calls go to whatever `WP_SITEURL` says — so using the tunnel URL avoids unnecessary round-trips out the tunnel and back.
* The QR code's `data-pay-callback` is built from `rest_url()`, so it automatically picks up the tunnel URL — no code change needed.
* The tunnel URL changes every run (anonymous quick tunnel). For a stable URL you'd need a named cloudflared tunnel bound to a Cloudflare account — overkill for local dev.

### Stopping and cleaning the environment

To stop the containers without losing data

```bash
npm run wp-env:stop
```

To reset the WordPress database for the current project you can use

```bash
npm run wp-env:clean
```

which runs `wp-env clean` under the hood. If you ever need a full reset for both development and test databases you can run, from the project root

```bash
wp-env clean all
wp-env start
```

or, as a last resort

```bash
wp-env destroy
wp-env start
```

Your plugin code lives in your git checkout, so cleaning or destroying the environment only affects the WordPress databases and containers, not your source files.

### Editing the plugin

Because the plugin is mounted into the container from the current directory, changes you make to PHP files are reflected immediately in the development site.

Typical loop

```bash
# In one terminal
npm run wp-env:start

# In another terminal
edit src/Gateway/CashuGateway.php
npm run build     # if you changed TS or JS assets
```

Then refresh the page in the browser. There is no need to restart the wp-env containers for normal plugin changes.

## Useful commands

Two small PHP scripts under `scripts/` regenerate the build outputs, wired up as npm scripts

```bash
npm run start
```

That will

* Regenerate the `languages/cashu-for-woocommerce.pot` file (via `scripts/build-pot.php`)
* Convert `readme.txt` into `README.md` (via `scripts/build-readme.php`)

You can run these individually if you prefer.

```bash
# Translations
npm run i18n

# readme conversion
npm run readme
```

## Code style and formatting

We use Prettier to keep the code style consistent, especially for PHP in the main plugin file and the src folder.

To check formatting

```bash
npm run format:check
```

To automatically fix formatting issues

```bash
npm run format
```

We also have JS/PHP code linting:

```bash
# Perform automatic fixes
npm run lint
# Check all linting issues resolved
npm run lint:check
```

Before opening a pull request, please run `npm run build` so your changes match the existing style.

## Internationalisation

Cashu For WooCommerce is prepared for translation using the `cashu-for-woocommerce` text domain.

When adding or updating strings in PHP

* Use standard WordPress translation functions, for example `__()`, `_e()`, `_x()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()` and so on
* Always pass `cashu-for-woocommerce` as the text domain

Example

```php
__( 'Pay with Cashu', 'cashu-for-woocommerce' );
```

If you are unsure whether your strings are correctly set up, run

```bash
npm run i18n
```

which will update text domains where needed and regenerate the main pot file.

When you have updated localized translations (eg: `cashu-for-woocommerce-fr_FR.po`), run either:

```bash
# Via wp-env (using wp-cli)
npm run i18n:mo

# Via the main build script (using gettext)
./build.sh
```

to create the `.mo` files.

## Development workflow

A typical workflow for a small change might look like this

1. Create a new branch from main

2. Make your code changes

3. Run the tools

	```bash
	# Runs build, format, lint, i18n, readme etc
	# Ensure it completes with "Done"
	npm run build
	```

4. Start or update the wp-env WordPress site and test the plugin in a WooCommerce store

	```bash
	npm run wp-env:start
	npm run wp-env:seed-store   # optional, to get demo products
	```

5. Commit your changes with a clear message

6. Push your branch and open a pull request

Please keep pull requests focused on a single change where possible, for example a bug fix, a new feature, or a documentation update. This makes review much easier.

## Reporting issues

If you find a bug or have a feature request, please include

* A clear description of the problem or idea
* Steps to reproduce the issue if it is a bug
* Your WordPress version, WooCommerce version and PHP version
* Any relevant error messages or logs

This information helps us understand and address the issue more quickly.

## Thank you

Every contribution, whether it is code, documentation, testing or feedback, helps improve Cashu For WooCommerce. Thank you for taking the time to help.
