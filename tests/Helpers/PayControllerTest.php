<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Helpers;

use Cashu\WC\Helpers\PayController;
use PHPUnit\Framework\TestCase;

/**
 * Focused unit tests for pure logic on PayController.
 *
 * Full request-cycle coverage (route registration, order lookup, mint round-trip)
 * requires the WordPress + WooCommerce runtime and lives in the wp-env integration
 * suite (see CONTRIBUTING.md). These tests only exercise behavior that has no WP
 * dependencies beyond the wp_hash stub in tests/bootstrap.php.
 */
final class PayControllerTest extends TestCase {

	public function test_payment_id_is_deterministic_per_order(): void {
		$a = PayController::payment_id_for( 42, 'wc_order_ABCDEFG' );
		$b = PayController::payment_id_for( 42, 'wc_order_ABCDEFG' );
		$this->assertSame( $a, $b );
	}

	public function test_payment_id_differs_for_different_orders(): void {
		$a = PayController::payment_id_for( 42, 'wc_order_ABCDEFG' );
		$b = PayController::payment_id_for( 43, 'wc_order_ABCDEFG' );
		$this->assertNotSame( $a, $b );
	}

	public function test_payment_id_differs_for_different_keys(): void {
		$a = PayController::payment_id_for( 42, 'wc_order_one' );
		$b = PayController::payment_id_for( 42, 'wc_order_two' );
		$this->assertNotSame( $a, $b );
	}

	public function test_payment_id_is_sixteen_hex_chars(): void {
		$id = PayController::payment_id_for( 1, 'wc_order_xyz' );
		$this->assertSame( 16, strlen( $id ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $id );
	}
}
