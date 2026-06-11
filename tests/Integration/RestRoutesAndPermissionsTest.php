<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\ConfirmMeltQuoteController;
use Cashu\WC\Helpers\PayController;
use Cashu\WC\Tests\IntegrationTestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST registration surface and the order-key permission gate, plus the
 * confirm endpoint's cheap pre-mint short-circuits (PAID, rate limit).
 */
final class RestRoutesAndPermissionsTest extends IntegrationTestCase {

	private function stubBaseline(): void {
		Functions\when( 'is_wp_error' )->alias( static fn ( $t ): bool => $t instanceof WP_Error );
		Functions\when( 'rest_ensure_response' )->alias( static fn ( $d ) => new WP_REST_Response( $d ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	private function request( array $params ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_params( $params );
		return $req;
	}

	public function test_controllers_register_their_routes_with_permission_gates(): void {
		$this->stubBaseline();
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $ns, string $route, array $args ) use ( &$routes ): bool {
				$routes[ $ns . $route ] = $args;
				return true;
			}
		);

		( new ConfirmMeltQuoteController() )->register_routes();
		( new PayController() )->register_routes();

		$this->assertArrayHasKey( 'cashu-wc/v1/confirm-melt-quote', $routes );
		$this->assertArrayHasKey( 'cashu-wc/v1/claim-melt-quote', $routes );
		$this->assertArrayHasKey( 'cashu-wc/v1/pay/(?P<order_id>\d+)/(?P<order_key>[A-Za-z0-9_-]+)', $routes );

		// The browser-polled routes gate on the order key at the permission
		// layer — a missing callback would expose order state to anonymous
		// polling.
		foreach ( array( 'cashu-wc/v1/confirm-melt-quote', 'cashu-wc/v1/claim-melt-quote' ) as $key ) {
			$this->assertIsCallable( $routes[ $key ]['permission_callback'], "route $key permission_callback" );
			$this->assertNotSame( '__return_true', $routes[ $key ]['permission_callback'], "route $key must not be public" );
		}

		// The pay route is deliberately public at the permission layer:
		// NUT-18 wallets POST with no WP cookies/nonce, so auth lives inside
		// pay() (key_is_valid + payment-id + rate limit). This pins that
		// choice so a refactor doesn't silently swap the auth model.
		$pay = $routes['cashu-wc/v1/pay/(?P<order_id>\d+)/(?P<order_key>[A-Za-z0-9_-]+)'];
		$this->assertSame( '__return_true', $pay['permission_callback'] );
	}

	// ── permission_callback: the order-key gate ──────────────────────────

	public function test_permission_denied_without_order_reference(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( false );
		$controller = new ConfirmMeltQuoteController();

		$this->assertFalse( $controller->permission_callback( $this->request( array() ) ) );
		$this->assertFalse( $controller->permission_callback( $this->request( array( 'order_id' => 42, 'order_key' => '' ) ) ) );
	}

	public function test_permission_denied_for_unknown_order(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( false );

		$this->assertFalse(
			( new ConfirmMeltQuoteController() )->permission_callback(
				$this->request( array( 'order_id' => 42, 'order_key' => 'k' ) )
			)
		);
	}

	public function test_permission_follows_order_key_validity(): void {
		$this->stubBaseline();
		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'key_is_valid' )->with( 'right' )->andReturn( true );
		$order->shouldReceive( 'key_is_valid' )->with( 'wrong' )->andReturn( false );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$controller = new ConfirmMeltQuoteController();

		$this->assertTrue( $controller->permission_callback( $this->request( array( 'order_id' => 42, 'order_key' => 'right' ) ) ) );
		$this->assertFalse( $controller->permission_callback( $this->request( array( 'order_id' => 42, 'order_key' => 'wrong' ) ) ) );
	}

	// ── confirm_melt_quote pre-mint short-circuits ───────────────────────

	public function test_paid_order_short_circuits_with_redirect_before_rate_limit(): void {
		$this->stubBaseline();
		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'is_paid' )->andReturn( true );
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://shop/thanks' );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		// A browser that just settled must get its redirect even with an
		// exhausted poll budget.
		Functions\expect( 'get_transient' )->never();

		$response = ( new ConfirmMeltQuoteController() )->confirm_melt_quote(
			$this->request( array( 'order_id' => 42, 'order_key' => 'k' ) )
		);

		$data = $response->get_data();
		$this->assertSame( 'PAID', $data['state'] );
		$this->assertSame( 'https://shop/thanks', $data['redirect'] );
	}

	public function test_unpaid_poll_is_rate_limited(): void {
		$this->stubBaseline();
		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_transient' )->justReturn( PHP_INT_MAX );

		$result = ( new ConfirmMeltQuoteController() )->confirm_melt_quote(
			$this->request( array( 'order_id' => 42, 'order_key' => 'k' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'cashu_rate_limited', $result->get_error_code() );
	}

	public function test_wrong_gateway_order_is_rejected_by_load(): void {
		$this->stubBaseline();
		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'stripe' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$result = ( new ConfirmMeltQuoteController() )->confirm_melt_quote(
			$this->request( array( 'order_id' => 42, 'order_key' => 'k' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'cashu_wrong_gateway', $result->get_error_code() );
	}
}
