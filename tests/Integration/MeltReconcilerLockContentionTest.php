<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\MeltReconciler;
use Cashu\WC\Tests\IntegrationTestCase;
use Mockery;

/**
 * Regression guard for the M2 fix: reconcile_one acquires the
 * pay-scope OrderLock at the top so its marker mutations cannot
 * race PayController / mark_paid. When the lock is held by another
 * process, reconcile_one must skip the order entirely — no mint
 * probe, no marker mutation — and leave it for the next cron tick.
 */
final class MeltReconcilerLockContentionTest extends IntegrationTestCase {

	private function stubBaseline(): void {
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( '__' )->returnArg( 1 );
	}

	/**
	 * Wire $wpdb so OrderLock::acquire fails the INSERT IGNORE (row
	 * exists) and the SELECT returns a not-yet-expired stored value —
	 * exactly the shape another process holding the lock produces.
	 */
	private function setUpContendedWpdb(): void {
		$future_value = bin2hex( random_bytes( 16 ) ) . '|' . ( time() + 30 );

		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql, ...$args ): string => $sql . '|' . implode( '|', array_map( 'strval', $args ) )
		);
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn ( string $s ): string => $s );
		// INSERT IGNORE returns 0 — row already exists.
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		// SELECT returns the live (still-fresh) row from the holder.
		$wpdb->shouldReceive( 'get_var' )->andReturn( $future_value );

		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_skips_order_when_pay_lock_is_held(): void {
		$this->stubBaseline();
		$this->setUpContendedWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q_busy',
				'_cashu_melt_pending_at'       => (string) ( time() - 600 ),
				'_cashu_melt_mint'             => 'https://m.example',
			)
		);
		// Mint MUST NOT be hit while the lock is held — that's the whole
		// point of taking the lock at the top of reconcile_one.
		Functions\expect( 'wp_remote_get' )->never();
		// payment_complete / add_order_note / save MUST NOT fire either.
		$order->shouldReceive( 'payment_complete' )->never();
		$order->shouldReceive( 'add_order_note' )->never();
		// Markers must be untouched — the holder owns them.
		Functions\when( 'wc_get_order' )->justReturn( $order );

		MeltReconciler::reconcile_one( $order );

		// The contended order is still pending-marked, ready for the next tick.
		$this->assertSame( 'q_busy', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}
}
