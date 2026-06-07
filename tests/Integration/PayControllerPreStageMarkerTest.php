<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\PayController;
use Cashu\WC\Tests\IntegrationTestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Covers the pre-stage pending-marker behaviour in PayController::pay():
 * the marker is written before request_melt_bolt11 runs and only cleared
 * on a confirmed-PAID outcome. Mid-flight throws and unknown states leave
 * the marker in place so confirm_melt_quote / MeltReconciler can pick up.
 */
final class PayControllerPreStageMarkerTest extends IntegrationTestCase {

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
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
		// NB: wp_hash is defined in tests/bootstrap.php before Patchwork loads,
		// so it cannot be overridden via Brain\Monkey here. The bootstrap stub
		// is deterministic and is used by both this test (to compute
		// $expected_id) and PayController::payment_id_for(), so the two sides
		// of the comparison agree without any override.
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url ) => parse_url( (string) $url ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		// hash_equals is a PHP built-in and not redefinable by Patchwork; the
		// real implementation does exactly what we need here.
	}

	private function requestWithBody( int $order_id, string $key, array $body ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_params( array(
			'order_id'  => $order_id,
			'order_key' => $key,
		) );
		$req->set_body( wp_json_encode( $body ) );
		return $req;
	}

	public function test_pre_stages_pending_marker_before_mint_call(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb(); // OrderLock acquire returns 1

		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );
		$proof       = array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'        => 'https://m.example',
			'_cashu_melt_quote_id'    => 'q_pre',
			'_cashu_melt_total'       => '10',
			'_cashu_spot_time'        => (string) time(),
			'_cashu_payment_hash'     => '',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'key_is_valid' )->andReturn( true );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		// wp_remote_post will throw a WP_Error to simulate a timeout. The marker
		// must already be on the order before the call returns.
		$wp_error         = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
		$captured_at_mint = null;
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing( function () use ( $order, $wp_error, &$captured_at_mint ) {
				// Capture marker state at the moment of the mint call so the
				// pre-stage-vs-post-stage ordering can be asserted reliably
				// (assert() would be compiled out under zend.assertions=-1).
				$captured_at_mint = $order->get_meta( '_cashu_melt_pending_quote_id' );
				return $wp_error;
			} );

		// The reconciliation probe (Task 2) will also be called; for this test
		// we stub it to return empty so we land in the keep-marker-return-502 branch.
		Functions\when( 'wp_remote_get' )->justReturn( $wp_error );

		$controller = new PayController();
		$response   = $controller->pay( $this->requestWithBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( $proof ),
		) ) );

		$this->assertSame( 'q_pre', $captured_at_mint, 'pre-stage marker must be persisted before mint call' );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'cashu_mint_error', $response->get_error_code() );
		// Marker survives the error.
		$this->assertSame( 'q_pre', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
		$this->assertNotSame( '', $order->get_meta( '_cashu_melt_pending_at' ) );
	}

	public function test_clears_marker_on_paid(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'     => 'https://m.example',
			'_cashu_melt_quote_id' => 'q_paid',
			'_cashu_melt_total'    => '10',
			'_cashu_spot_time'     => (string) time(),
			'_cashu_payment_hash'  => '',
			// Pre-existing marker simulating a prior pre-stage write. The PAID
			// branch must drop both keys; assertions below verify they're gone.
			'_cashu_melt_pending_quote_id' => 'q_paid',
			'_cashu_melt_pending_at'       => (string) ( time() - 5 ),
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
		$response   = $controller->pay( $this->requestWithBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' ) ),
		) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'ok', $response->get_data()['status'] );
		// Marker dropped on PAID.
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_at' ) );
	}

	public function test_probe_finalises_order_when_mint_says_paid_after_throw(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'     => 'https://m.example',
			'_cashu_melt_quote_id' => 'q_throw_paid',
			'_cashu_melt_total'    => '10',
			'_cashu_spot_time'     => (string) time(),
			'_cashu_payment_hash'  => '',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'key_is_valid' )->andReturn( true );
		$order->shouldReceive( 'payment_complete' )->once()->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		// POST throws (timeout); follow-up GET probe says PAID.
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			new WP_Error( 'http_request_failed', 'timeout' )
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'state'            => 'PAID',
				'payment_preimage' => str_repeat( '0', 64 ),
				'amount'           => 10,
			) ),
		) );

		$controller = new PayController();
		$response   = $controller->pay( $this->requestWithBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' ) ),
		) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'ok', $response->get_data()['status'] );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_probe_returns_pending_keeps_marker(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'     => 'https://m.example',
			'_cashu_melt_quote_id' => 'q_throw_pending',
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
			'body'     => json_encode( array( 'state' => 'PENDING' ) ),
		) );

		$controller = new PayController();
		$response   = $controller->pay( $this->requestWithBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' ) ),
		) ) );

		$this->assertSame( 'pending', $response->get_data()['status'] );
		$this->assertSame( 'q_throw_pending', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_probe_returns_unpaid_drops_marker(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'     => 'https://m.example',
			'_cashu_melt_quote_id' => 'q_throw_unpaid',
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

		$controller = new PayController();
		$response   = $controller->pay( $this->requestWithBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' ) ),
		) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'cashu_mint_error', $response->get_error_code() );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_probe_failure_keeps_marker_for_later_reconciliation(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'     => 'https://m.example',
			'_cashu_melt_quote_id' => 'q_double_fail',
			'_cashu_melt_total'    => '10',
			'_cashu_spot_time'     => (string) time(),
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'key_is_valid' )->andReturn( true );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			new WP_Error( 'http_request_failed', 'timeout' )
		);
		// Probe also fails — mint genuinely unreachable.
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			new WP_Error( 'http_request_failed', 'timeout' )
		);

		$controller = new PayController();
		$response   = $controller->pay( $this->requestWithBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' ) ),
		) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'cashu_mint_error', $response->get_error_code() );
		// Marker MUST persist so MeltReconciler can pick this up later.
		$this->assertSame( 'q_double_fail', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_state_mismatch_probe_paid_finalises_order(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'     => 'https://m.example',
			'_cashu_melt_quote_id' => 'q_mismatch_paid',
			'_cashu_melt_total'    => '10',
			'_cashu_spot_time'     => (string) time(),
			'_cashu_payment_hash'  => '',
		) );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'key_is_valid' )->andReturn( true );
		$order->shouldReceive( 'payment_complete' )->once()->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		// POST returns 200 with an unknown state — drops into the state-mismatch
		// branch. Probe says PAID — order finalises.
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'state' => 'UNKNOWN' ) ),
		) );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'state'            => 'PAID',
				'payment_preimage' => str_repeat( '0', 64 ),
				'amount'           => 10,
			) ),
		) );

		$controller = new PayController();
		$response   = $controller->pay( $this->requestWithBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' ) ),
		) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'ok', $response->get_data()['status'] );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_state_mismatch_probe_failure_returns_cashu_unpaid(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$expected_id = substr( wp_hash( '42|key|cashu_payment_id' ), 0, 16 );

		$order = $this->mockOrder( 42, array(
			'_cashu_melt_mint'     => 'https://m.example',
			'_cashu_melt_quote_id' => 'q_mismatch_unknown',
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
		// Probe also fails (or returns nothing useful) — terminal must be
		// cashu_unpaid (not cashu_mint_error) and marker must persist.
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			new WP_Error( 'http_request_failed', 'mint unreachable' )
		);

		$controller = new PayController();
		$response   = $controller->pay( $this->requestWithBody( 42, 'key', array(
			'mint'   => 'https://m.example',
			'unit'   => 'sat',
			'id'     => $expected_id,
			'proofs' => array( array( 'id' => 'k', 'amount' => 10, 'secret' => 's', 'C' => 'c' ) ),
		) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'cashu_unpaid', $response->get_error_code() );
		// Marker persists for cron / polling reconciliation.
		$this->assertSame( 'q_mismatch_unknown', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}
}
