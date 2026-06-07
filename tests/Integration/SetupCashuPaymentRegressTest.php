<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Helpers\CashuPaths;
use Cashu\WC\Tests\IntegrationTestCase;
use ReflectionMethod;

/**
 * Regression guard for the H3 fix: setup_cashu_payment must NOT call
 * update_status('pending') if a concurrent payment_complete (cashu leg,
 * LN leg, or MeltReconciler) flips the order to a paid status between
 * the post-lock is_paid() check and the update_status call.
 *
 * Without the second wc_get_order + is_paid recheck immediately before
 * update_status, the order would silently regress 'processing' →
 * 'pending', firing WC's status-transition actions on a paid order.
 */
final class SetupCashuPaymentRegressTest extends IntegrationTestCase {

	private function stubGatewayBaseline(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				$values = array(
					'cashu_lightning_address' => 'me@example.com',
					'cashu_trusted_mint'      => 'https://mint.example',
					'cashu_paths'             => CashuPaths::DEFAULT_PATHS,
				);
				return $values[ $key ] ?? $default;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_x' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	private function invokeSetup( CashuGateway $gw, $order ): void {
		$reflection = new ReflectionMethod( CashuGateway::class, 'setup_cashu_payment' );
		$reflection->setAccessible( true );
		$reflection->invoke( $gw, $order );
	}

	public function test_does_not_update_status_when_concurrent_payment_completes_after_lock(): void {
		$this->stubGatewayBaseline();
		$this->setUpFakeWpdb();

		// $fresh is what wc_get_order returns on the first call (under
		// the lock) — has is_paid=false so the controller proceeds past
		// the initial guard. It MUST NOT receive update_status, because
		// the second wc_get_order ($latest) reports paid.
		$fresh = $this->mockOrder( 42 );
		$fresh->shouldReceive( 'is_paid' )->andReturn( false );
		$fresh->shouldReceive( 'update_status' )->never();

		// $latest simulates a concurrent payment_complete that landed
		// between the lock acquire and the recheck.
		$latest = $this->mockOrder( 42 );
		$latest->shouldReceive( 'is_paid' )->andReturn( true );

		// Outer-scope order (the one passed in) — its is_paid is hit at
		// the top of setup_cashu_payment before the lock dance.
		$outer = $this->mockOrder( 42 );
		$outer->shouldReceive( 'is_paid' )->andReturn( false );

		$call = 0;
		Functions\when( 'wc_get_order' )->alias(
			static function () use ( $fresh, $latest, &$call ) {
				++$call;
				// Call 1 = $fresh under lock; call 2 = $latest recheck.
				return 1 === $call ? $fresh : $latest;
			}
		);

		$gw = new CashuGateway();
		$this->invokeSetup( $gw, $outer );

		// Mockery's never() expectation on update_status is the assertion;
		// add an explicit check that the recheck ran (2 wc_get_order calls).
		$this->assertSame( 2, $call, 'expected fresh + latest wc_get_order calls' );
	}

	public function test_calls_update_status_when_latest_recheck_still_unpaid(): void {
		// Sanity counterpart: when the recheck also reports unpaid, the
		// fix MUST NOT regress to silently never calling update_status.
		$this->stubGatewayBaseline();
		$this->setUpFakeWpdb();

		$fresh = $this->mockOrder( 42 );
		$fresh->shouldReceive( 'is_paid' )->andReturn( false );
		$fresh->shouldReceive( 'update_status' )->once();
		// setup_cashu_payment continues past update_status into mint
		// helpers; allow get_total / get_currency / add_order_note to
		// be called any number of times without asserting their shape.
		// We just need to stop the test before the mint round-trip.
		$fresh->shouldReceive( 'get_total' )->andReturn( '0.00' );
		$fresh->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		$fresh->shouldReceive( 'add_order_note' )->andReturn( 1 );
		$fresh->shouldReceive( 'save' )->andReturn( 42 );

		$latest = $this->mockOrder( 42 );
		$latest->shouldReceive( 'is_paid' )->andReturn( false );

		$outer = $this->mockOrder( 42 );
		$outer->shouldReceive( 'is_paid' )->andReturn( false );

		$call = 0;
		Functions\when( 'wc_get_order' )->alias(
			static function () use ( $fresh, $latest, &$call ) {
				++$call;
				return 1 === $call ? $fresh : $latest;
			}
		);
		// Don't actually fetch a spot quote — short-circuit get_total_sats
		// via a $0 total which the gateway treats as no-op.
		// (get_total returns '0.00', so the mint path is skipped.)
		// Actually the gateway calls get_total_sats which involves spot
		// quote fetch; easier to let setup throw and catch it here, since
		// the assertion we care about (update_status called once) fires
		// before any mint call.
		try {
			$gw = new CashuGateway();
			$this->invokeSetup( $gw, $outer );
		} catch ( \Throwable $e ) {
			// Expected past update_status — fine.
		}

		$this->assertSame( 2, $call );
	}
}
