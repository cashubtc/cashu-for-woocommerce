<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\MeltReconciler;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * Covers the cron-driven reconciliation handler. Per-state probe outcomes,
 * per-order throttle, and the 24h age-out path.
 */
final class MeltReconcilerTest extends IntegrationTestCase {

	private function stubBaseline(): void {
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( static fn ( $r ): int => $r['response']['code'] ?? 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( static fn ( $r ): string => $r['body'] ?? '' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( '__' )->returnArg( 1 );
	}

	public function test_paid_state_finalises_order(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_pending_quote_id' => 'q_recon_paid',
			'_cashu_melt_pending_at'	   => (string) ( time() - 600 ),
			'_cashu_melt_mint'			   => 'https://m.example',
			'_cashu_melt_total'			   => '10',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'q_recon_paid' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'	   => json_encode( array(
				'state'			   => 'PAID',
				'amount'		   => 10,
				'payment_preimage' => str_repeat( 'a', 64 ),
			) ),
		) );

		MeltReconciler::reconcile_one( $order );

		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_pending_state_leaves_marker(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_pending_quote_id' => 'q_still_pending',
			'_cashu_melt_pending_at'	   => (string) ( time() - 600 ),
			'_cashu_melt_mint'			   => 'https://m.example',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'	   => json_encode( array( 'state' => 'PENDING' ) ),
		) );

		MeltReconciler::reconcile_one( $order );

		$this->assertSame( 'q_still_pending', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_unpaid_state_drops_marker(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_pending_quote_id' => 'q_unpaid',
			'_cashu_melt_pending_at'	   => (string) ( time() - 600 ),
			'_cashu_melt_mint'			   => 'https://m.example',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'	   => json_encode( array( 'state' => 'UNPAID' ) ),
		) );

		MeltReconciler::reconcile_one( $order );

		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_aged_out_marker_writes_orphan_note(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_pending_quote_id' => 'q_ancient',
			'_cashu_melt_pending_at'	   => (string) ( time() - DAY_IN_SECONDS - 60 ),
			'_cashu_melt_mint'			   => 'https://m.example',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		// Mint MUST NOT be hit when the marker is past TTL.
		Functions\expect( 'wp_remote_get' )->never();

		MeltReconciler::reconcile_one( $order );

		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_throttle_skips_recently_probed_orders(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		// Transient says "probed within the last hour" — must skip the mint.
		Functions\when( 'get_transient' )->justReturn( '1' );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_pending_quote_id' => 'q_throttled',
			'_cashu_melt_pending_at'	   => (string) ( time() - 600 ),
			'_cashu_melt_mint'			   => 'https://m.example',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		MeltReconciler::reconcile_one( $order );

		$this->assertSame( 'q_throttled', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_already_paid_order_drops_marker_without_probing(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_pending_quote_id' => 'q_paid_externally',
			'_cashu_melt_pending_at'	   => (string) ( time() - 600 ),
			'_cashu_melt_mint'			   => 'https://m.example',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( true );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		MeltReconciler::reconcile_one( $order );

		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}
}
