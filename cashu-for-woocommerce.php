<?php
/**
 * Plugin Name: Cashu For WooCommerce
 * Plugin URI:  https://github.com/robwoodgate/cashu-for-woocommerce
 * Description: This plugin adds a secure Cashu payment gateway to your WooCommerce store, allowing you to receive bitcoin ecash payments to your lightning address.
 * Author:      Rob Woodgate
 * Author URI:  https://www.github.com/robwoodgate
 * License:     MIT
 * License URI: https://github.com/robwoodgate/cashu-for-woocommerce/blob/main/license.txt
 * Text Domain: cashu-for-woocommerce
 * Domain Path: /languages
 * Version:     0.2.0
 *
 * @package     Cashu_For_Woocommerce
 */

// * No direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CASHU_WC_VERSION', '0.2.0' );
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
	}
);

// * Instantiate main plugin
\Cashu\WC\CashuWCPlugin::instance()->run();
