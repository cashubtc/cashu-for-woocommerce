<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\CashuWCPlugin;
use Cashu\WC\Helpers\ConfirmMeltQuoteController;
use Cashu\WC\Helpers\MeltReconciler;
use Cashu\WC\Tests\IntegrationTestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Pins the cancelled-order revive guard: a customer whose
 * order was paid once and later cancelled by the admin must NOT be able to
 * flip it back to processing by replaying their preimage (claim endpoint)
 * or by the cron re-probing the mint's PAID quote state (MeltReconciler).
 *
 * Also pins the deliberate flip side: a FIRST settlement on a cancelled
 * order (WC hold-stock auto-cancel racing a slow melt) must still complete
 * — the sentinel only exists after payment_complete has fired once — and
 * WC's auto-cancel is vetoed while a melt is pending at the mint.
 */
final class SettlementGuardTest extends IntegrationTestCase {

	/** sha256(hex2bin('deadbeef')) */
	private const VALID_PREIMAGE = 'deadbeef';
	private const VALID_HASH     = '5f78c33274e43fa9de5659265c1d917e25c03722dcb0b8d27db8d5feaa813953';

	private function stubBaseline(): void {
		Functions\when( 'is_wp_error' )->alias(
			static fn ( $thing ): bool => $thing instanceof WP_Error
		);
		Functions\when( 'rest_ensure_response' )->alias(
			static fn ( $data ) => new WP_REST_Response( $data )
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( static fn ( $r ): int => $r['response']['code'] ?? 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( static fn ( $r ): string => $r['body'] ?? '' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	private function claimRequest( int $order_id, string $key, string $preimage = '' ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_params(
			array(
				'order_id'  => $order_id,
				'order_key' => $key,
				'preimage'  => $preimage,
			)
		);
		return $req;
	}

	public function test_preimage_replay_on_cancelled_order_does_not_recomplete(): void {
		// Order was paid once (sentinel set), then admin-cancelled and
		// refunded out-of-band. The customer replays their stored preimage.
		// payment_complete MUST NOT fire; the browser gets PENDING (never a
		// PAID + redirect for an order that wasn't re-completed).
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_replay',
				'_cashu_payment_hash'  => self::VALID_HASH,
				'_cashu_melt_mint'     => 'https://m.example',
				'_cashu_paid_once'     => (string) ( time() - 3600 ),
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldReceive( 'payment_complete' )->never();
		// One audit note explaining the refusal — and only one, even though
		// the endpoint allows repeat attempts.
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();

		$response = $controller->claim_melt_quote( $this->claimRequest( 42, 'k', self::VALID_PREIMAGE ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'PENDING', $response->get_data()['state'] );

		// Second replay attempt: still blocked, no second note (Mockery's
		// once() above is the assertion).
		$response = $controller->claim_melt_quote( $this->claimRequest( 42, 'k', self::VALID_PREIMAGE ) );
		$this->assertSame( 'PENDING', $response->get_data()['state'] );
	}

	public function test_reconciler_does_not_revive_cancelled_paid_once_order(): void {
		// Cron probes the mint, which reports the single-use quote PAID —
		// that can only re-prove the original settlement. With the sentinel
		// set, the order must not be re-completed; markers are dropped so
		// the cron stops re-probing.
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q_replay',
				'_cashu_melt_pending_at'       => (string) ( time() - 600 ),
				'_cashu_melt_mint'             => 'https://m.example',
				'_cashu_melt_total'            => '10',
				'_cashu_paid_once'             => (string) ( time() - 3600 ),
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldReceive( 'payment_complete' )->never();
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'state'  => 'PAID',
						'amount' => 10,
					)
				),
			)
		);

		MeltReconciler::reconcile_one( $order );

		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_at' ) );
	}

	public function test_first_settlement_without_sentinel_still_completes(): void {
		// Flip side: an order that has never been through payment_complete
		// (no sentinel) finalises normally even from the reconciler — this
		// is the WC-auto-cancelled-while-melt-pending recovery path.
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q_first',
				'_cashu_melt_pending_at'       => (string) ( time() - 600 ),
				'_cashu_melt_mint'             => 'https://m.example',
				'_cashu_melt_total'            => '10',
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'q_first' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'state'  => 'PAID',
						'amount' => 10,
					)
				),
			)
		);

		MeltReconciler::reconcile_one( $order );

		// Sentinel is now set for any future replay.
		$this->assertNotSame( '', $order->get_meta( '_cashu_paid_once' ) );
	}

	public function test_auto_cancel_vetoed_while_melt_pending(): void {
		$this->stubBaseline();

		$pending = $this->mockOrder(
			42,
			array( '_cashu_melt_pending_quote_id' => 'q_inflight' )
		);
		$this->assertFalse(
			CashuWCPlugin::preventCancelDuringSettlement( true, $pending ),
			'order with in-flight melt must not be auto-cancelled'
		);

		$idle = $this->mockOrder( 43 );
		$this->assertTrue(
			CashuWCPlugin::preventCancelDuringSettlement( true, $idle ),
			'order with no pending melt cancels normally'
		);

		$other = $this->mockOrder( 44, array( '_cashu_melt_pending_quote_id' => 'q' ) );
		$other->shouldReceive( 'get_payment_method' )->andReturn( 'cod' );
		$this->assertTrue(
			CashuWCPlugin::preventCancelDuringSettlement( true, $other ),
			'non-cashu orders are untouched'
		);
	}
}
