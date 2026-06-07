<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\ConfirmMeltQuoteController;
use Cashu\WC\Tests\IntegrationTestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Regression for the previous-round #1 bug: a cashu-leg order where the
 * customer's POST landed (proofs handed to the mint) but the mint's LN
 * routing took longer than the spot quote's 15-minute expiry, then
 * eventually settled.
 *
 * Pre-fix, the confirm-melt-quote poll would:
 *   1. is_paid() -> false
 *   2. check_confirm_rate_limit -> ok
 *   3. spot_expiry check -> EXPIRED, return EXPIRED
 *
 * The customer's proofs are at the mint, the mint has paid the invoice,
 * but the order is marked EXPIRED. Customer support nightmare.
 *
 * Post-fix (61c9936's resolve_pending_melt branch lives BEFORE the spot
 * expiry check), the poll:
 *   1. is_paid() -> false
 *   2. check_confirm_rate_limit -> ok
 *   3. resolve_pending_melt sees the marker, queries the mint, sees PAID,
 *      runs mark_paid -> returns PAID + redirect
 *
 * Even with a long-expired spot timestamp.
 */
final class SpotExpiredMintPaidTest extends IntegrationTestCase {

	private const VALID_PREIMAGE = 'deadbeef';
	private const VALID_HASH     = '5f78c33274e43fa9de5659265c1d917e25c03722dcb0b8d27db8d5feaa813953';

	private function stubControllerBaseline(): void {
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

	private function request( int $order_id, string $order_key ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_params(
			array(
				'order_id'  => $order_id,
				'order_key' => $order_key,
			)
		);
		return $req;
	}

	public function test_spot_expired_but_mint_paid_marks_paid_not_expired(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$now              = time();
		$ancient_spot     = $now - 1200;   // 20 min ago, well past 15-min expiry
		$recent_pending   = $now - 60;     // 1 min ago, comfortably within 1h TTL

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id'         => 'q_settled',
				'_cashu_melt_pending_quote_id' => 'q_settled',
				'_cashu_melt_pending_at'       => (string) $recent_pending,
				'_cashu_melt_mint'             => 'https://m.example',
				'_cashu_payment_hash'          => self::VALID_HASH,
				'_cashu_melt_total'            => '1000',
				'_cashu_spot_time'             => (string) $ancient_spot,
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false, false, false );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'q_settled' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://example.test/thankyou' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'state'            => 'PAID',
						'payment_preimage' => self::VALID_PREIMAGE,
						'amount'           => 1000,
					)
				),
			)
		);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		// The regression: pre-fix this would be 'EXPIRED'. Post-fix
		// resolve_pending_melt runs first and finds the mint has paid.
		$this->assertSame( 'PAID', $data['state'], 'spot-expired pending order with mint-PAID must redirect, not expire' );
		$this->assertSame( 'https://example.test/thankyou', $data['redirect'] );

		// Markers cleared so subsequent polls don't re-hit the mint.
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_at' ) );
	}

	public function test_spot_expired_no_pending_marker_returns_expired(): void {
		// Control: same setup but without the pending marker. With nothing
		// to resolve, the spot-expiry branch correctly fires EXPIRED.
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$now          = time();
		$ancient_spot = $now - 1200;

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_spot_time' => (string) $ancient_spot,
				// no _cashu_melt_pending_quote_id
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'payment_complete' )->never();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		$this->assertSame( 'EXPIRED', $response->get_data()['state'] );
	}

	public function test_spot_expired_pending_aged_out_returns_expired_not_paid(): void {
		// Edge case: spot is long expired AND the pending marker is past TTL.
		// resolve_pending_melt clears the marker without hitting the mint,
		// then we fall through to the spot-expiry check -> EXPIRED. No
		// customer is settled by a mint we never asked.
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$now             = time();
		$ancient_spot    = $now - 1200;
		$aged_pending_at = $now - DAY_IN_SECONDS - 60;

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q_stuck',
				'_cashu_melt_pending_at'       => (string) $aged_pending_at,
				'_cashu_melt_mint'             => 'https://m.example',
				'_cashu_spot_time'             => (string) $ancient_spot,
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'payment_complete' )->never();
		// Aged-out marker writes an orphan note before falling through to
		// the spot-expiry check.
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		$this->assertSame( 'EXPIRED', $response->get_data()['state'] );
	}
}
