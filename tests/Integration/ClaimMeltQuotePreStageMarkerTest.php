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
 * Regression guard for the M1 fix: claim_melt_quote pre-stages the
 * pending-melt marker BEFORE the mint state probe. Without this, a
 * tab-close or network blip between meltProofsBolt11 (mint received
 * proofs) and the marker write inside the PENDING-state branch would
 * leave MeltReconciler nothing to sweep — the merchant's LN address
 * would receive the funds but the order would never flip to processing.
 */
final class ClaimMeltQuotePreStageMarkerTest extends IntegrationTestCase {

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

	private function request( int $order_id, string $key ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_params(
			array(
				'order_id'  => $order_id,
				'order_key' => $key,
			)
		);
		return $req;
	}

	public function test_marker_is_written_before_mint_probe(): void {
		// The wallet's meltProofsBolt11 has just completed at the mint and
		// the browser is calling /claim-melt-quote to finalise. If the
		// mint probe HTTP call hangs/throws AFTER the proofs are committed
		// at the mint, the marker is the ONLY signal MeltReconciler can
		// follow to sweep the order. The pre-stage ordering must therefore
		// be observable at the moment of the probe.
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_prestage',
				'_cashu_melt_mint'     => 'https://m.example',
				'_cashu_payment_hash'  => str_repeat( 'a', 64 ),
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		// Capture marker state at the moment of the mint call. Asserting via
		// an extra variable rather than assert() so this works under any
		// zend.assertions setting.
		$captured_at_probe = null;
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturnUsing(
				function () use ( $order, &$captured_at_probe ) {
					$captured_at_probe = array(
						'quote' => $order->get_meta( '_cashu_melt_pending_quote_id' ),
						'at'    => $order->get_meta( '_cashu_melt_pending_at' ),
					);
					return new WP_Error( 'http_request_failed', 'simulated timeout' );
				}
			);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->request( 42, 'k' ) );

		// Marker MUST already be on the order at the moment of the probe.
		$this->assertSame( 'q_prestage', $captured_at_probe['quote'], 'pre-stage marker must be persisted before mint probe' );
		$this->assertNotSame( '', $captured_at_probe['at'] );

		// Mint timeout surfaces as cashu_mint_error; marker remains so the
		// cron sweep / next browser poll can resolve it.
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'cashu_mint_error', $response->get_error_code() );
		$this->assertSame( 'q_prestage', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_marker_cleared_on_positive_unpaid(): void {
		// Mint positively reports UNPAID: proofs were never consumed, the
		// pre-staged marker is dead weight and must be cleared so cron
		// doesn't waste mint hits probing a quote with no commitment.
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_unpaid',
				'_cashu_melt_mint'     => 'https://m.example',
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'state' => 'UNPAID' ) ),
			)
		);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->request( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'UNPAID', $response->get_data()['state'] );
		// Marker dropped, last-attempt stamped.
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_at' ) );
		$this->assertNotSame( '', $order->get_meta( '_cashu_last_payment_attempt_at' ) );
	}

	public function test_bad_preimage_stages_marker_before_rejecting(): void {
		// A wallet reaching /claim-melt-quote has just melted at the mint.
		// If the preimage it reports is corrupt, the 400 must still leave a
		// pending marker behind — the proofs may be committed at the mint,
		// and without the marker MeltReconciler has nothing to sweep.
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_badpre',
				'_cashu_melt_mint'     => 'https://m.example',
				// sha256(hex2bin('deadbeef')) — does not match '00' below.
				'_cashu_payment_hash'  => '5f78c33274e43fa9de5659265c1d917e25c03722dcb0b8d27db8d5feaa813953',
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'payment_complete' )->never();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		// Still no mint fallback on a bad preimage — that's the
		// amplification vector the reject exists to close.
		Functions\expect( 'wp_remote_get' )->never();

		$req = $this->request( 42, 'k' );
		$req->set_params(
			array(
				'order_id'  => 42,
				'order_key' => 'k',
				'preimage'  => '00',
			)
		);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $req );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'cashu_bad_preimage', $response->get_error_code() );
		$this->assertSame( 'q_badpre', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
		$this->assertNotSame( '', $order->get_meta( '_cashu_melt_pending_at' ) );
	}

	public function test_marker_survives_on_pending_state(): void {
		// Mint reports PENDING — proofs committed, LN still routing. The
		// pre-staged marker must remain (the pre-stage already wrote it,
		// so this branch should be a no-op vs. the marker).
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_pending',
				'_cashu_melt_mint'     => 'https://m.example',
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'state' => 'PENDING' ) ),
			)
		);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->request( 42, 'k' ) );

		$this->assertSame( 'PENDING', $response->get_data()['state'] );
		$this->assertSame( 'q_pending', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
		$this->assertNotSame( '', $order->get_meta( '_cashu_melt_pending_at' ) );
	}
}
