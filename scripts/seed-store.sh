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
wp option update woocommerce_task_list_hidden yes
wp option update woocommerce_task_list_welcome_modal_dismissed yes
wp option update woocommerce_show_marketplace_suggestions no
wp option update woocommerce_allow_tracking no
