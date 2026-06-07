<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\ConfirmMeltQuoteController;
use Cashu\WC\Helpers\MeltReconciler;
use Cashu\WC\Helpers\PayController;
use Cashu\WC\Tests\IntegrationTestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Lifecycle of `_cashu_last_payment_attempt_at` across PayController,
 * ConfirmMeltQuoteController, and MeltReconciler. Surfaced via the
 * `last_attempt` field on the confirm-melt-quote response and consumed by
 * the receipt page's "previous attempt didn't reach the mint" banner.
 *
 * Writers: every code path that confirms the mint did NOT consume the
 * customer's proofs/payment stamps the timestamp. Clearers: every
 * successful settlement path drops it. Pinning both halves prevents a
 * subtle drift where a banner persists across a successful payment, or
 * vanishes too early before the customer can re-attempt.
 *
 * Also pins the 3f39289 claim-melt-quote PENDING-marker fix as a side
 * effect — that path is symmetric with the UNPAID timestamp stamp.
 */
final class PreviousAttemptFailedTest extends IntegrationTestCase {

	/** sha256(hex2bin('deadbeef')) — used for the no-mint-hit PAID path. */
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
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url ) => parse_url( (string) $url ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	private function confirmRequest( int $order_id, string $order_key, string $preimage = '' ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_params(
			array(
				'order_id'  => $order_id,
				'order_key' => $order_key,
				'preimage'  => $preimage,
			)
		);
		return $req;
	}

	private function payRequestBody( int $order_id, string $key, array $body ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_params(
			array(
				'order_id'  => $order_id,
				'order_key' => $key,
			)
		);
		$req->set_body( wp_json_encode( $body ) );
		return $req;
	}

	// === PayController writers ===

	/**
	 * Pre-stage UNPAID probe (line ~290 in PayController) — the post-throw
	 * recovery branch that drops the marker on a positive UNPAID from the
	 * mint. Must also stamp the timestamp so a returning customer sees the
	 * failed-attempt banner instead of "Waiting for payment".
	 */
	public function test_paycontroller_pre_stage_unpaid_stamps_timestamp(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();
		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'     => 'https://m.example',
			'_cashu_melt_quote_id' => 'q_pre_unpaid',
			'_cashu_melt_total'    => '10',
			'_cashu_spot_time'     => (string) time(),
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'key_is_valid' )->andReturn( true );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			new WP_Error( 'http_request_failed', 'timeout' )
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'state' => 'UNPAID' ) ),
		) );

		$before     = time();
		$controller = new PayController();
		$response   = $controller->pay( $this->payRequestBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' ) ),
		) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$stamped = (int) $order->get_meta( '_cashu_last_payment_attempt_at' );
		$this->assertGreaterThanOrEqual( $before, $stamped, 'timestamp must be stamped at the UNPAID drop' );
		$this->assertLessThanOrEqual( time(), $stamped );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ), 'UNPAID also drops the marker' );
	}

	/**
	 * State-mismatch UNPAID probe (line ~346 in PayController) — POST returns
	 * 200 with an unknown state, follow-up GET probe returns UNPAID. Both
	 * paths end at the same drop-marker-stamp-timestamp behaviour.
	 */
	public function test_paycontroller_state_mismatch_unpaid_stamps_timestamp(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();
		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'     => 'https://m.example',
			'_cashu_melt_quote_id' => 'q_mismatch_unpaid',
			'_cashu_melt_total'    => '10',
			'_cashu_spot_time'     => (string) time(),
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'key_is_valid' )->andReturn( true );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'state' => 'UNKNOWN' ) ),
		) );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'state' => 'UNPAID' ) ),
		) );

		$before     = time();
		$controller = new PayController();
		$response   = $controller->pay( $this->payRequestBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' ) ),
		) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'cashu_unpaid', $response->get_error_code() );
		$stamped = (int) $order->get_meta( '_cashu_last_payment_attempt_at' );
		$this->assertGreaterThanOrEqual( $before, $stamped );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	/**
	 * finalise_paid clears any pre-existing timestamp. The clear ensures that
	 * a customer who retried successfully doesn't continue to see the
	 * "previous attempt failed" banner on the order-received page.
	 */
	public function test_paycontroller_finalise_paid_clears_timestamp(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();
		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'                 => 'https://m.example',
			'_cashu_melt_quote_id'             => 'q_retry_paid',
			'_cashu_melt_total'                => '10',
			'_cashu_spot_time'                 => (string) time(),
			'_cashu_payment_hash'              => '',
			// Pre-existing stamp from the prior failed attempt.
			'_cashu_last_payment_attempt_at'   => (string) ( time() - 120 ),
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'key_is_valid' )->andReturn( true );
		$order->shouldReceive( 'payment_complete' )->once()->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'state' => 'PAID', 'amount' => 10, 'change' => array() ) ),
		) );

		$controller = new PayController();
		$response   = $controller->pay( $this->payRequestBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' ) ),
		) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'ok', $response->get_data()['status'] );
		$this->assertSame( '', $order->get_meta( '_cashu_last_payment_attempt_at' ), 'PAID must drop the prior failed-attempt stamp' );
	}

	// === ConfirmMeltQuoteController::resolve_pending_melt writer ===

	/**
	 * resolve_pending_melt's UNPAID branch (line ~332) — the poll-time probe
	 * that drops a stale marker when the mint reports the merchant melt
	 * quote was never paid. Symmetric with PayController's pre-stage drop:
	 * also stamps the timestamp.
	 */
	public function test_resolve_pending_melt_unpaid_drops_marker_and_stamps(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_pending_quote_id' => 'q_poll_unpaid',
			'_cashu_melt_pending_at'       => (string) ( time() - 30 ),
			'_cashu_melt_mint'             => 'https://m.example',
			'_cashu_spot_time'             => (string) time(),
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'state' => 'UNPAID' ) ),
		) );

		$before     = time();
		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->confirmRequest( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_at' ) );
		$stamped = (int) $order->get_meta( '_cashu_last_payment_attempt_at' );
		$this->assertGreaterThanOrEqual( $before, $stamped );
		// After marker drop the call falls through to the regular UNPAID
		// branch, which should now carry the freshly-stamped last_attempt.
		$body = $response->get_data();
		$this->assertSame( 'UNPAID', $body['state'] );
		$this->assertSame( $stamped, (int) $body['last_attempt'] );
	}

	// === ConfirmMeltQuoteController::confirm_melt_quote response shape ===

	/**
	 * UNPAID response surfaces `last_attempt` as a unix ts when the meta is
	 * set — this is the wire payload the deriveOrderStatusActions reducer
	 * inspects to render the failed-attempt banner.
	 */
	public function test_confirm_melt_quote_unpaid_surfaces_last_attempt(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$stamp = time() - 30;
		$order = $this->mockOrder( 42, array(
			// No pending marker — straight UNPAID branch.
			'_cashu_spot_time'               => (string) time(),
			'_cashu_last_payment_attempt_at' => (string) $stamp,
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->confirmRequest( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$body = $response->get_data();
		$this->assertSame( 'UNPAID', $body['state'] );
		$this->assertSame( $stamp, (int) $body['last_attempt'] );
	}

	/**
	 * UNPAID response sends `last_attempt: null` when the meta is unset —
	 * a fresh-page-load on a never-paid order. The browser uses null to
	 * suppress the banner and keep the default "Waiting for payment" copy.
	 */
	public function test_confirm_melt_quote_unpaid_null_when_not_stamped(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_spot_time' => (string) time(),
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->confirmRequest( 42, 'k' ) );

		$body = $response->get_data();
		$this->assertSame( 'UNPAID', $body['state'] );
		$this->assertNull( $body['last_attempt'] );
	}

	// === ConfirmMeltQuoteController::claim_melt_quote (3f39289 fix) ===

	/**
	 * 3f39289: claim_melt_quote writes the pending-melt marker when the
	 * mint probe returns PENDING. Without this, the LN-leg's in-flight
	 * gap leaves no server-side trace for MeltReconciler to pick up if the
	 * customer closes the tab. NUT: does NOT stamp the failed-attempt
	 * timestamp — that's only for confirmed UNPAID.
	 */
	public function test_claim_melt_quote_pending_writes_marker(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_quote_id' => 'q_ln_pending',
			'_cashu_payment_hash'  => self::VALID_HASH,
			'_cashu_melt_mint'     => 'https://m.example',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'payment_complete' )->never();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'state' => 'PENDING' ) ),
		) );

		$before     = time();
		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->confirmRequest( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'PENDING', $response->get_data()['state'] );
		$this->assertSame( 'q_ln_pending', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
		$pending_at = (int) $order->get_meta( '_cashu_melt_pending_at' );
		$this->assertGreaterThanOrEqual( $before, $pending_at );
		$this->assertSame( '', $order->get_meta( '_cashu_last_payment_attempt_at' ), 'PENDING is in-flight, not a failed attempt' );
	}

	/**
	 * 3f39289 symmetric path: claim_melt_quote stamps the timestamp on a
	 * positive UNPAID from the mint probe. Does NOT write the pending
	 * marker (the proofs were never consumed, no reconciliation needed).
	 */
	public function test_claim_melt_quote_unpaid_stamps_timestamp(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_quote_id' => 'q_ln_unpaid',
			'_cashu_payment_hash'  => self::VALID_HASH,
			'_cashu_melt_mint'     => 'https://m.example',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'payment_complete' )->never();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'state' => 'UNPAID' ) ),
		) );

		$before     = time();
		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->confirmRequest( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'UNPAID', $response->get_data()['state'] );
		$stamped = (int) $order->get_meta( '_cashu_last_payment_attempt_at' );
		$this->assertGreaterThanOrEqual( $before, $stamped );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ), 'UNPAID must NOT write the pending marker' );
	}

	// === ConfirmMeltQuoteController::mark_paid clearer ===

	/**
	 * mark_paid (line ~597) clears the timestamp on a successful settlement.
	 * Driven here via claim_melt_quote with a client-supplied valid preimage
	 * (the no-mint-hit fast path) so the clear is asserted in isolation.
	 */
	public function test_mark_paid_clears_timestamp(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_quote_id'             => 'q_retry_paid',
			'_cashu_payment_hash'              => self::VALID_HASH,
			'_cashu_melt_mint'                 => 'https://m.example',
			// Pre-existing stamp from the prior failed attempt.
			'_cashu_last_payment_attempt_at'   => (string) ( time() - 120 ),
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false, false, false );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'q_retry_paid' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://example.test/thankyou' );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->confirmRequest( 42, 'k', self::VALID_PREIMAGE ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'PAID', $response->get_data()['state'] );
		$this->assertSame( '', $order->get_meta( '_cashu_last_payment_attempt_at' ) );
	}

	// === MeltReconciler::finalise_paid clearer ===

	/**
	 * MeltReconciler clears the timestamp on a cron-driven successful
	 * settlement. Covers the case where the customer closed the tab mid-melt
	 * and the cron finishes the work later — the stamp from the original
	 * failed attempt must not persist past reconciliation.
	 */
	public function test_melt_reconciler_finalise_paid_clears_timestamp(): void {
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
		$this->setUpFakeWpdb();

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_pending_quote_id'   => 'q_recon_retry',
			'_cashu_melt_pending_at'         => (string) ( time() - 600 ),
			'_cashu_melt_mint'               => 'https://m.example',
			'_cashu_melt_total'              => '10',
			'_cashu_last_payment_attempt_at' => (string) ( time() - 500 ),
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'q_recon_retry' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'state'            => 'PAID',
				'amount'           => 10,
				'payment_preimage' => str_repeat( 'a', 64 ),
			) ),
		) );

		MeltReconciler::reconcile_one( $order );

		$this->assertSame( '', $order->get_meta( '_cashu_last_payment_attempt_at' ) );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}
}
