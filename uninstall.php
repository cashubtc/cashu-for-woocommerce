<?php
/**
 * Uninstall — runs when the plugin is deleted, not on deactivate.
 *
 * Wipes global plugin state: settings, the WC-managed gateway row,
 * transient caches, rate-limit counters, and OrderLock rows.
 *
 * Per-order meta (_cashu_melt_*, _cashu_mint_quote_*) is intentionally
 * PRESERVED — it's transaction history on completed orders and survives
 * the plugin's removal, the same way WooCommerce keeps order records
 * when a payment gateway is uninstalled.
 *
 * @package Cashu_For_Woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Per-site cleanup. Runs once on single-site installs, or once per
 * subsite under switch_to_blog() on multisite networks.
 */
function cashu_wc_uninstall_cleanup() {
	global $wpdb;

	// Named global options — settings, migration flag, review-nag state.
	// woocommerce_cashu_default_settings is the WC-managed gateway row.
	$options = array(
		'cashu_paths',
		'cashu_default_path',
		'cashu_enabled',
		'cashu_lightning_address',
		'cashu_trusted_mint',
		'cashu_modal_checkout',
		'cashu_debug',
		'cashu_settings_migrated',
		'cashu_review_dismissed_forever',
		'cashu_review_earliest_show',
		'woocommerce_cashu_default_settings',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Named transient — the "Remind me later" 30-day timer.
	delete_transient( 'cashu_review_dismissed' );

	// Pattern-prefixed transients. Each transient writes two rows in
	// wp_options: _transient_<key> and _transient_timeout_<key>. Direct
	// DELETE with LIKE because wp_options has no native bulk-by-prefix
	// API; caching this DELETE result would defeat the cleanup.
	$transient_prefixes = array(
		'cashu_btc_spot_cb_',         // Coinbase BTC/fiat spot cache.
		'cashu_btc_spot_cg_',         // CoinGecko BTC/fiat spot cache.
		'cashu_melt_state_',          // Pending melt state cache.
		'cashu_wc_claim_attempts_',   // /claim rate-limit counter.
		'cashu_wc_confirm_attempts_', // /confirm rate-limit counter.
	);
	foreach ( $transient_prefixes as $prefix ) {
		$like_value = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like_value
			)
		);

		$like_timeout = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like_timeout
			)
		);
	}

	// OrderLock rows live directly in wp_options (not as transients). The
	// per-row TTL would expire them eventually, but uninstall is the right
	// moment to be tidy rather than leave stale lock rows lying around.
	$lock_like = $wpdb->esc_like( 'cashu_wc_lock_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$lock_like
		)
	);

	// Clear the recurring reconciliation cron event. Hard-coded hook name
	// because MeltReconciler may not be autoloaded in uninstall context.
	wp_clear_scheduled_hook( 'cashu_wc_reconcile_pending_melts' );
}

if ( is_multisite() ) {
	$cashu_wc_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $cashu_wc_site_ids as $cashu_wc_site_id ) {
		switch_to_blog( (int) $cashu_wc_site_id );
		cashu_wc_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	cashu_wc_uninstall_cleanup();
}
