<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\MeltReconciler;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * The cron sweep entry point (reconcile_pending) and reconcile_one's
 * skip-without-probing edges. Companion to MeltReconcilerTest, which
 * covers the probe/finalise paths.
 */
final class MeltReconcilerSweepTest extends IntegrationTestCase {

	private function stubBaseline(): void {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	public function test_sweep_queries_the_pending_marker_oldest_first_and_bounded(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$captured = array();
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$captured ): array {
				$captured = $args;
				return array();
			}
		);

		MeltReconciler::reconcile_pending();

		// Bounded batch, oldest marker first — a backlog larger than one
		// batch must not starve old orders.
		$this->assertSame( 20, $captured['limit'] );
		$this->assertSame( '_cashu_melt_pending_at', $captured['meta_key'] );
		$this->assertSame( 'ASC', $captured['order'] );
		$this->assertSame( '_cashu_melt_pending_quote_id', $captured['meta_query'][0]['key'] );
		// Cancelled/failed included: a melt that settles after WC's
		// hold-stock auto-cancel must still finalise the order.
		$this->assertContains( 'cancelled', $captured['status'] );
		$this->assertContains( 'failed', $captured['status'] );
		$this->assertSame( 'cashu_default', $captured['payment_method'] );
	}

	public function test_sweep_reconciles_orders_and_skips_non_order_entries(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		// A paid order with leftover markers: reconcile_one's cheapest real
		// path — markers cleared, no mint probe. The stray non-order entry
		// in the result set must be skipped, not fatal.
		$order = $this->mockOrder( 42, array( '_cashu_melt_pending_quote_id' => 'q1' ) );
		$order->shouldReceive( 'is_paid' )->andReturn( true );

		Functions\when( 'wc_get_orders' )->justReturn( array( 'not-an-order', $order ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		MeltReconciler::reconcile_pending();

		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_sweep_tolerates_non_array_query_result(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_orders' )->justReturn( false );

		MeltReconciler::reconcile_pending();

		$this->assertTrue( true ); // no fatal is the assertion
	}

	// ── reconcile_one skip edges: no probe may be attempted ─────────────

	public function test_skips_when_order_vanishes_under_the_lock(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$order = $this->mockOrder( 42 );
		Functions\when( 'wc_get_order' )->justReturn( false );
		Functions\expect( 'wp_remote_get' )->never();

		MeltReconciler::reconcile_one( $order );

		$this->assertTrue( true );
	}

	public function test_skips_when_marker_already_cleared(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$order = $this->mockOrder( 42 ); // no pending marker meta
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		MeltReconciler::reconcile_one( $order );

		$this->assertTrue( true );
	}

	public function test_skips_when_order_has_no_mint_url(): void {
		// Marker without a mint URL is unprobeable — must skip quietly, not
		// guess a mint from current settings (it may have changed since).
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q1',
				'_cashu_melt_pending_at'       => (string) time(),
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		MeltReconciler::reconcile_one( $order );

		// Marker preserved: a later tick (or admin retry) can still resolve.
		$this->assertSame( 'q1', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_paid_race_inside_finalise_is_a_noop(): void {
		// is_paid flips between reconcile_one's check and finalise (another
		// path settled mid-probe): finalise must not re-complete.
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q1',
				'_cashu_melt_pending_at'       => (string) time(),
				'_cashu_melt_mint'             => 'https://mint.example',
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false, true );
		$order->shouldReceive( 'payment_complete' )->never();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'is_wp_error' )->alias( static fn ( $t ): bool => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( static fn ( $r ): int => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( static fn ( $r ): string => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'state' => 'PAID' ) ),
			)
		);

		MeltReconciler::reconcile_one( $order, true );

		$this->assertTrue( true ); // never() on payment_complete is the assertion
	}
}
