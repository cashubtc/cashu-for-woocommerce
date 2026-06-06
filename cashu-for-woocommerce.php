<?php
/**
 * Plugin Name: Cashu For WooCommerce
 * Plugin URI:  https://www.github.com/robwoodgate
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

// PSR-4 autoload for the plugin's own namespace. The plugin has no
// production Composer dependencies, so we don't ship vendor/ — a hand-
// rolled loader for Cashu\WC\* → src/ is enough and avoids the
// composer.json/vendor coupling that triggers the wp.org Plugin Check
// "missing_composer_json_file" warning.
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

// * Instantiate main plugin
\Cashu\WC\CashuWCPlugin::instance()->run();
