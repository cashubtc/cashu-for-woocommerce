#!/usr/bin/env bash
# Seed the wp-env WooCommerce sandbox: import sample products and dismiss
# the WC onboarding wizard so a fresh start lands in a usable admin
# without clicking through the setup screens. Idempotent — safe to re-run.

set -euo pipefail

wp() { npx wp-env run cli wp "$@"; }

# Sample products (WC ships a ~20-product XML in its plugin folder).
wp plugin install wordpress-importer --activate
wp import wp-content/plugins/woocommerce/sample-data/sample_products.xml --authors=create

# Store address + currency.
wp option update woocommerce_store_address "1 Demo Street"
wp option update woocommerce_store_city "London"
wp option update woocommerce_default_country "GB"
wp option update woocommerce_store_postcode "SW1A 1AA"
wp option update woocommerce_currency "GBP"

# Dismiss the onboarding wizard / task list / welcome modal.
wp option update woocommerce_onboarding_profile '{"completed":true,"skipped":true}' --format=json
# Set the list directly: WC's deprecated woocommerce_task_list_hidden shim
# reports a failed update, which aborts this script.
wp option update woocommerce_task_list_hidden_lists '["setup"]' --format=json
wp option update woocommerce_task_list_welcome_modal_dismissed yes
wp option update woocommerce_show_marketplace_suggestions no
wp option update woocommerce_allow_tracking no

# Open the storefront (fresh installs start in "coming soon" mode) and enable
# the gateway so checkout offers Cashu once a mint and address are set.
wp option update woocommerce_coming_soon no
wp wc payment_gateway update cashu_default --enabled=true --user=admin
