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
		public function get_payment_method(): string { return ''; }
		public function get_meta(string $key, bool $single = true) { return ''; }
		public function update_meta_data(string $key, $value): void {}
		public function delete_meta_data(string $key): void {}
		public function save(): int { return 0; }
		public function read_meta_data(bool $force_read = false): void {}
		public function update_status(string $status, string $note = ''): bool { return true; }
		public function payment_complete(string $txn_id = ''): bool { return true; }
		public function add_order_note(string $note, int $is_customer_note = 0, bool $added_by_user = false): int { return 0; }
		public function has_status($status): bool { return false; }
		public function is_paid(): bool { return false; }
		public function get_checkout_payment_url(bool $on_checkout = false): string { return ''; }
		public function get_checkout_order_received_url(): string { return ''; }
		public function key_is_valid(string $key): bool { return true; }
	}
}

// 6. WP_REST_Request skeleton. Only get_param() is used by the controllers
//    under test; Mockery covers callers that need richer behaviour.
if (!class_exists('WP_REST_Request')) {
	class WP_REST_Request {
		private array $params = array();
		public function set_params(array $params): void { $this->params = $params; }
		public function get_param(string $key) { return $this->params[$key] ?? null; }
	}
}

// 7. WP_REST_Response stand-in — concrete class so rest_ensure_response can
//    return one. data() reflects what controllers serialise back.
if (!class_exists('WP_REST_Response')) {
	class WP_REST_Response {
		private $data;
		private int $status;
		public function __construct($data = null, int $status = 200) {
			$this->data = $data;
			$this->status = $status;
		}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
	}
}

// 8. WP_Error skeleton. The plugin only ever calls get_error_message() on it.
if (!class_exists('WP_Error')) {
	class WP_Error {
		private string $code;
		private string $message;
		private array $data;
		public function __construct(string $code = '', string $message = '', array $data = array()) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

// 9. WP_REST_Server constants used in route registration. None of the tests
//    register routes, but the controller's `use WP_REST_Server` requires the
//    class to be resolvable.
if (!class_exists('WP_REST_Server')) {
	class WP_REST_Server {
		const READABLE = 'GET';
		const CREATABLE = 'POST';
	}
}

if (!defined('CASHU_WC_BIP177_SYMBOL')) {
	define('CASHU_WC_BIP177_SYMBOL', '₿');
}

// 10. Skeleton WC_Logger. Logger::debug/error new this up and call
//     ->debug()/->error() on it. We don't care what it does, just that the
//     methods exist.
if (!class_exists('WC_Logger')) {
	class WC_Logger {
		public function debug(string $message, array $context = array()): void {}
		public function error(string $message, array $context = array()): void {}
		public function info(string $message, array $context = array()): void {}
		public function warning(string $message, array $context = array()): void {}
	}
}

if (!function_exists('wc_print_r')) {
	function wc_print_r($value, bool $return = false) {
		return print_r($value, $return);
	}
}

// 11. Skeleton WC_Admin_Settings. The plugin's ValidateGlobalSettings calls
//     ::add_error() / ::add_message() to surface validation feedback on the
//     settings screen. Tests capture into static arrays and reset() between
//     cases — call WC_Admin_Settings::reset() in setUp.
if (!class_exists('WC_Admin_Settings')) {
	class WC_Admin_Settings {
		public static array $errors   = array();
		public static array $messages = array();
		public static function add_error( $message ): void { self::$errors[] = (string) $message; }
		public static function add_message( $message ): void { self::$messages[] = (string) $message; }
		public static function reset(): void {
			self::$errors   = array();
			self::$messages = array();
		}
	}
}

echo "Bootstrap loaded – Cashu ready for testing!\n";
