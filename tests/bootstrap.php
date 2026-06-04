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
if (!defined('MINUTE_IN_SECONDS')) {
	define('MINUTE_IN_SECONDS', 60);
}
if (!defined('DAY_IN_SECONDS')) {
	define('DAY_IN_SECONDS', 86400);
}
if (!defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}

// 3. Minimal stubs for the WordPress functions touched by pure helpers in src/.
//    Tests that need richer WP/WC behaviour use Brain\Monkey + Mockery via
//    Cashu\WC\Tests\IntegrationTestCase. Tests under tests/Helpers/ run as
//    plain PHPUnit and rely only on these stubs.
if (!function_exists('wp_hash')) {
	function wp_hash(string $data, string $scheme = 'auth'): string {
		// Deterministic hex output, enough for derivation-helper tests.
		return hash_hmac('md5', $data, $scheme . '|cashu-test-salt');
	}
}

// 4. Skeleton WC_Payment_Gateway so CashuGateway can be loaded under tests.
//    Real WP gateways extend WC's class which itself extends WC_Settings_API,
//    both of which we replace with minimal no-op shims here. Tests that need
//    gateway-level behaviour mock through Mockery on top of this skeleton.
if (!class_exists('WC_Payment_Gateway')) {
	class WC_Payment_Gateway {
		public $id = '';
		public $icon = '';
		public $method_title = '';
		public $method_description = '';
		public $has_fields = false;
		public $supports = array();
		public $title = '';
		public $description = '';
		public $enabled = 'no';
		public $form_fields = array();
		public $settings = array();
		public function init_settings(): void {}
		public function process_admin_options(): bool { return true; }
		public function get_option( string $key, $default = '' ) {
			return $this->settings[ $key ] ?? $default;
		}
		public function update_option( string $key, $value = '' ): bool {
			$this->settings[ $key ] = $value;
			return true;
		}
		public function generate_settings_html( array $form_fields = array(), bool $echo = true ) {
			return '';
		}
	}
}

// 5. Skeleton WC_Order class so Mockery can mock instances. Only methods the
//    plugin actually calls are listed; tests that need other methods extend
//    via Mockery::mock(WC_Order::class)->shouldReceive('newMethod').
if (!class_exists('WC_Order')) {
	class WC_Order {
		public function get_id(): int { return 0; }
		public function get_status(): string { return ''; }
		public function get_total(): string { return '0'; }
		public function get_currency(): string { return 'USD'; }
		public function get_order_key(): string { return ''; }
		public function get_meta(string $key, bool $single = true) { return ''; }
		public function update_meta_data(string $key, $value): void {}
		public function delete_meta_data(string $key): void {}
		public function save(): int { return 0; }
		public function update_status(string $status, string $note = ''): bool { return true; }
		public function payment_complete(string $txn_id = ''): bool { return true; }
		public function add_order_note(string $note, int $is_customer_note = 0, bool $added_by_user = false): int { return 0; }
		public function has_status($status): bool { return false; }
		public function is_paid(): bool { return false; }
		public function get_checkout_payment_url(bool $on_checkout = false): string { return ''; }
	}
}

echo "Bootstrap loaded – Cashu ready for testing!\n";
