<?php
declare(strict_types=1);

// 1. Load Composer autoloader
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
	echo "Run: composer install\n";
	exit(1);
}
require_once $autoload;

// 2. Define plugin constants (so your classes don't explode)
if (!defined('CASHU_WC_VERSION')) {
	define('CASHU_WC_VERSION', '0.1.1');
}
if (!defined('CASHU_WC_PATH')) {
	define('CASHU_WC_PATH', dirname(__DIR__));
}
if (!defined('CASHU_WC_URL')) {
	define('CASHU_WC_URL', 'file://' . dirname(__DIR__));
}
if (!defined('CASHU_WC_BASE')) {
	define('CASHU_WC_BASE', 'cashu-for-woocommerce');
}
if (!defined('HOUR_IN_SECONDS')) {
	define('HOUR_IN_SECONDS', 3600);
}

// 3. Minimal stubs for the WordPress functions touched by pure helpers in src/.
//    Anything that needs a real database, HTTP client, or order object should
//    be exercised inside wp-env, not here.
if (!function_exists('wp_hash')) {
	function wp_hash(string $data, string $scheme = 'auth'): string {
		// Deterministic hex output, enough for derivation-helper tests.
		return hash_hmac('md5', $data, $scheme . '|cashu-test-salt');
	}
}

echo "Bootstrap loaded – Cashu ready for testing!\n";
