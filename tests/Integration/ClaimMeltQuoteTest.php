<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\ConfirmMeltQuoteController;
use Cashu\WC\Tests\IntegrationTestCase;
use Mockery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Covers ConfirmMeltQuoteController::claim_melt_quote — the lightning-leg
 * one-shot finalizer the browser hits after wallet.meltProofsBolt11().
 *
 * Pins: when the mint-supplied preimage cross-check fails, the order is
 * still marked PAID (the mint's
 * PAID state is authoritative) but the bad preimage is dropped from the
 * stored audit trail rather than recorded as if it were valid.
 *
 * Also pins: client-supplied preimage that mismatches is rejected outright
 * (not silently bumped to a mint fallback — that would be the
 * leaked-order-key amplification path); client-supplied correct preimage
 * marks paid with no mint hit; mint UNPAID does NOT mark the order paid.
 */
final class ClaimMeltQuoteTest extends IntegrationTestCase {

	/** sha256(hex2bin('deadbeef')) */
	private const VALID_PREIMAGE = 'deadbeef';
	private const VALID_HASH     = '5f78c33274e43fa9de5659265c1d917e25c03722dcb0b8d27db8d5feaa813953';

	/** sha256(hex2bin('00')) — distinct from VALID_HASH for mismatch tests. */
	private const OTHER_PREIMAGE = '00';

	/** Wire the WP function shims most controller tests need. */
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

		// No rate-limit hits on first invocation.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	/**
	 * Build a WP_REST_Request with the params the controller reads.
	 */
	private function request( int $order_id, string $order_key, string $preimage = '' ): WP_REST_Request {
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

	public function test_client_preimage_matches_marks_paid_with_no_mint_hit(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_42',
				'_cashu_payment_hash'  => self::VALID_HASH,
				'_cashu_melt_mint'     => 'https://m.example',
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false, false, false );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'q_42' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://example.test/thankyou' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		// CRITICAL: wp_remote_get MUST NOT be called when the client-supplied
		// preimage matches. Functions\expect with ->never() would also work.
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->request( 42, 'k', self::VALID_PREIMAGE ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'PAID', $data['state'] );
		$this->assertSame( 'https://example.test/thankyou', $data['redirect'] );
	}

	public function test_client_preimage_mismatch_returns_wp_error_no_mint_fallback(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_42',
				'_cashu_payment_hash'  => self::VALID_HASH, // doesn't match OTHER_PREIMAGE
				'_cashu_melt_mint'     => 'https://m.example',
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'payment_complete' )->never();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->request( 42, 'k', self::OTHER_PREIMAGE ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'cashu_bad_preimage', $response->get_error_code() );
	}

	public function test_no_client_preimage_falls_back_to_mint_state(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_42',
				'_cashu_payment_hash'  => self::VALID_HASH,
				'_cashu_melt_mint'     => 'https://m.example',
				'_cashu_melt_total'    => '1000',
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false, false, false );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'q_42' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://example.test/thankyou' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$captured_url = null;
		Functions\expect( 'wp_remote_get' )->once()
			->andReturnUsing(
				function ( string $url, array $args ) use ( &$captured_url ) {
					$captured_url = $url;
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => json_encode(
							array(
								'state'            => 'PAID',
								'payment_preimage' => self::VALID_PREIMAGE,
								'amount'           => 1000,
							)
						),
					);
				}
			);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->request( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'PAID', $response->get_data()['state'] );
		// Per H1 fix: query the order's stored mint URL, not the current setting.
		$this->assertStringStartsWith( 'https://m.example/v1/melt/quote/bolt11/', $captured_url );
		$this->assertStringContainsString( 'q_42', $captured_url );
	}

	public function test_no_client_preimage_mint_paid_but_preimage_mismatches_still_marks_paid(): void {
		// The M1+L1 second-pass fix. Mint says PAID — authoritative — so we
		// mark the order paid, but the mint's preimage didn't actually hash
		// to the stored payment_hash, so we drop it from the audit trail
		// instead of recording it as a verified preimage.
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$captured_preimage = null;

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_42',
				'_cashu_payment_hash'  => self::VALID_HASH,
				'_cashu_melt_mint'     => 'https://m.example',
				'_cashu_melt_total'    => '1000',
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false, false, false );

		// We can't easily intercept update_meta_data via the array-backed
		// mockOrder helper, but we know preimage flows into update_meta_data
		// ONLY if it's non-empty. We assert via the order note instead.
		$order->shouldReceive( 'payment_complete' )->once()->with( 'q_42' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )
			->once()
			->andReturnUsing(
				function ( string $note ) use ( &$captured_preimage ): int {
					$captured_preimage = $note;
					return 1;
				}
			);
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://example.test/thankyou' );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'state'            => 'PAID',
						// Mint returned a preimage that doesn't hash to VALID_HASH.
						'payment_preimage' => self::OTHER_PREIMAGE,
						'amount'           => 1000,
					)
				),
			)
		);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->request( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'PAID', $response->get_data()['state'] );
		// Bad preimage was blanked before being recorded in the order note —
		// the "Payment preimage:" line should be empty, not contain the
		// mint's lie. (Can't substring-match OTHER_PREIMAGE because it's "00"
		// which collides with the amount "1000".)
		$this->assertNotNull( $captured_preimage );
		$this->assertMatchesRegularExpression(
			'/Payment preimage:\s*$/',
			$captured_preimage
		);
	}

	public function test_no_client_preimage_mint_unpaid_does_not_mark_paid(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_42',
				'_cashu_payment_hash'  => self::VALID_HASH,
				'_cashu_melt_mint'     => 'https://m.example',
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'payment_complete' )->never();

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
	}

	public function test_already_paid_short_circuits_to_paid(): void {
		$this->stubControllerBaseline();

		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'is_paid' )->andReturn( true );
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://example.test/thankyou' );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->request( 42, 'k' ) );

		$this->assertSame( 'PAID', $response->get_data()['state'] );
		$this->assertSame( 'https://example.test/thankyou', $response->get_data()['redirect'] );
	}
}
