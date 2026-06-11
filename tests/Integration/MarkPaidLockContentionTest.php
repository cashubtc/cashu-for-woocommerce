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
 * Covers mark_paid under lock contention: it returns false when it can't
 * take the pay-scope lock within budget, and callers surface PENDING to
 * the browser rather than fake-PAID + redirect.
 *
 * Drives mark_paid via the public claim_melt_quote path so we don't poke
 * private methods. The lock contention is simulated by wiring $wpdb so
 * INSERT IGNORE returns 0 (row already exists) and SELECT returns a
 * not-yet-expired timestamp on the acquire attempts — wait_for_release
 * sees the lock disappear once (null) so the loop exits in microseconds
 * rather than spinning for the full 60s budget.
 */
final class MarkPaidLockContentionTest extends IntegrationTestCase {

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

	/**
	 * Wire $wpdb so the first OrderLock::acquire fails (row exists, not
	 * expired), wait_for_release sees null on its first poll (lock vanished),
	 * and the second OrderLock::acquire also fails — the exact "contention
	 * timeout" path mark_paid surfaces as false.
	 */
	private function setUpContendedWpdb(): void {
		$future_ts = (string) ( time() + 30 );

		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			fn ( string $sql, ...$args ): string => $sql . '|' . implode( '|', array_map( 'strval', $args ) )
		);
		// Two INSERT IGNORE calls: both find a row already there.
		$wpdb->shouldReceive( 'query' )->andReturn( 0, 0 );
		// SELECT sequence: held, then disappeared (so wait_for_release
		// returns immediately without sleeping), then held again.
		$wpdb->shouldReceive( 'get_var' )->andReturn(
			$future_ts,
			null,
			$future_ts
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

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

	public function test_claim_returns_pending_when_lock_contended_with_client_preimage(): void {
		$this->stubControllerBaseline();
		$this->setUpContendedWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id' => 'q_42',
				'_cashu_payment_hash'  => self::VALID_HASH,
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		// mark_paid must NOT reach payment_complete on a lock-contention failure.
		$order->shouldReceive( 'payment_complete' )->never();
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://example.test/thankyou' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->request( 42, 'k', self::VALID_PREIMAGE ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		// The whole point of H5: a contention failure must NOT claim PAID +
		// redirect. PENDING tells the browser to keep polling.
		$this->assertSame( 'PENDING', $data['state'] );
		$this->assertArrayNotHasKey( 'redirect', $data );
	}

	public function test_claim_returns_pending_when_lock_contended_via_mint_state(): void {
		// Same surfacing through the no-preimage / mint-PAID branch.
		$this->stubControllerBaseline();
		$this->setUpContendedWpdb();

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
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://example.test/thankyou' );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'state'            => 'PAID',
						'payment_preimage' => self::VALID_PREIMAGE,
					)
				),
			)
		);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->claim_melt_quote( $this->request( 42, 'k' ) );

		$this->assertSame( 'PENDING', $response->get_data()['state'] );
	}
}
