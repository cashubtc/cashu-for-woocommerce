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
 * Both REST boundaries (confirm-melt-quote poll, NUT-18 pay POST) slide the
 * spot window instead of expiring/rejecting while the customer's mint quote
 * is still payable and drift stays inside SpotWindow's tolerance band.
 */
final class SlidingWindowEndpointsTest extends IntegrationTestCase {

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

	/** Order whose spot window lapsed but whose quote is still payable. */
	private function lapsedOrder( int $standing_sats ) {
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_spot_total'         => (string) $standing_sats,
				'_cashu_spot_time'          => (string) ( time() - 1000 ),
				'_cashu_mint_quote_id'      => 'mq1',
				'_cashu_mint_quote_expiry'  => (string) ( time() + HOUR_IN_SECONDS ),
				'_cashu_mint_quote_created' => (string) ( time() - 1000 ),
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'key_is_valid' )->andReturn( true );
		$order->shouldReceive( 'get_total' )->andReturn( '100.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		return $order;
	}

	private function primePrice(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_transient' )->alias(
			static fn ( string $key ) => str_starts_with( $key, 'cashu_btc_spot_cb_' ) ? 100000.0 : false
		);
	}

	public function test_confirm_slides_and_returns_unpaid_with_fresh_expiry(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();
		$this->primePrice();
		$order = $this->lapsedOrder( 100000 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		$data = $response->get_data();
		$this->assertSame( 'UNPAID', $data['state'] );
		$this->assertGreaterThan( time(), $data['expiry'] );
	}

	public function test_confirm_expires_beyond_the_band(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();
		$this->primePrice();
		$order = $this->lapsedOrder( 99000 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		$this->assertSame( 'EXPIRED', $response->get_data()['state'] );
	}

	public function test_pay_endpoint_slides_before_rejecting_410(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();
		$this->primePrice();
		$order = $this->lapsedOrder( 100000 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$controller = new \Cashu\WC\Helpers\PayController();
		$result     = $controller->pay( $this->request( 42, 'k' ) );

		// Window slid, so the request proceeds past the 410 and fails
		// later on the (absent) JSON body instead.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'cashu_expired', $result->get_error_code() );
	}

	public function test_pay_endpoint_still_410s_beyond_the_band(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();
		$this->primePrice();
		$order = $this->lapsedOrder( 99000 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$controller = new \Cashu\WC\Helpers\PayController();
		$result     = $controller->pay( $this->request( 42, 'k' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cashu_expired', $result->get_error_code() );
	}
}
