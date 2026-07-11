<?php
/**
 * Plugin Name: Cashu For WooCommerce
 * Plugin URI:  https://github.com/cashubtc/cashu-for-woocommerce
 * Description: This plugin adds a secure Cashu payment gateway to your WooCommerce store, allowing you to receive bitcoin ecash payments to your lightning address.
 * Author:      Rob Woodgate
 * Author URI:  https://www.github.com/robwoodgate
 * License:     MIT
 * License URI: https://github.com/cashubtc/cashu-for-woocommerce/blob/main/license.txt
 * Text Domain: cashu-for-woocommerce
 * Requires Plugins: woocommerce
 * Version:     0.4.0
 *
 * @package     Cashu_For_Woocommerce
 */

// * No direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CASHU_WC_VERSION', '0.4.0' );
define( 'CASHU_WC_FILE', __FILE__ ); // absolute path to main plugin file
define( 'CASHU_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'CASHU_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'CASHU_WC_BASE', plugin_basename( __FILE__ ) ); // plugin_folder/plugin_name.php
define( 'CASHU_WC_BIP177_SYMBOL', '₿' );

// PSR-4 autoload for the plugin's own namespace
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strncmp( 'Cashu\\WC\\', $class_name, 9 ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, 9 ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( ! wp_next_scheduled( \Cashu\WC\Helpers\MeltReconciler::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', \Cashu\WC\Helpers\MeltReconciler::HOOK );
		}
		// Offset from the reconciler so the two hourly jobs don't habitually
		// share a wp-cron request (a fatal in one tick would take out both).
		if ( ! wp_next_scheduled( \Cashu\WC\Helpers\MintLimits::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', \Cashu\WC\Helpers\MintLimits::HOOK );
		}
		if ( ! wp_next_scheduled( \Cashu\WC\Helpers\MintQuoteReconciler::HOOK ) ) {
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, 'hourly', \Cashu\WC\Helpers\MintQuoteReconciler::HOOK );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		$timestamp = wp_next_scheduled( \Cashu\WC\Helpers\MeltReconciler::HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, \Cashu\WC\Helpers\MeltReconciler::HOOK );
		}
		wp_clear_scheduled_hook( \Cashu\WC\Helpers\MeltReconciler::HOOK );
		wp_clear_scheduled_hook( \Cashu\WC\Helpers\MintLimits::HOOK );
		wp_clear_scheduled_hook( \Cashu\WC\Helpers\MintQuoteReconciler::HOOK );
	}
);

// * Instantiate main plugin
\Cashu\WC\CashuWCPlugin::instance()->run();
