<?php
/**
 * Test-only stub for the WC 8.7+ `Automattic\WooCommerce\Enums\OrderStatus`
 * enum-like class. Referenced by CashuGateway::setup_cashu_payment via
 * `OrderStatus::PENDING`; the production class isn't loaded under PHPUnit
 * because WC core isn't installed in the test environment. Only the
 * constants are needed — we don't model the full PHP enum surface.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Enums;

if ( ! class_exists( OrderStatus::class ) ) {
	// phpcs:ignore PEAR.NamingConventions.ValidClassName.Invalid
	class OrderStatus {
		const PENDING    = 'pending';
		const ON_HOLD    = 'on-hold';
		const PROCESSING = 'processing';
		const COMPLETED  = 'completed';
		const FAILED     = 'failed';
		const CANCELLED  = 'cancelled';
		const REFUNDED   = 'refunded';
	}
}
