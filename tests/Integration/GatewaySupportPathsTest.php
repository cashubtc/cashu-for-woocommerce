<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Helpers\CashuPaths;
use Cashu\WC\Tests\IntegrationTestCase;
use ReflectionMethod;

/**
 * get_total_sats spot-quote lifecycle and process_payment outcome paths.
 */
final class GatewaySupportPathsTest extends IntegrationTestCase {

	private function stubGatewayBaseline(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				$values = array(
					'cashu_lightning_address' => 'me@example.com',
					'cashu_trusted_mint'      => 'https://mint.example',
					'cashu_paths'             => CashuPaths::DEFAULT_PATHS,
				);
				return $values[ $key ] ?? $default;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_x' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	private function gateway(): CashuGateway {
		$gw          = new CashuGateway();
		$gw->enabled = 'yes';
		return $gw;
	}

	private function totalSats( $order ): int {
		$m = new ReflectionMethod( CashuGateway::class, 'get_total_sats' );
		$m->setAccessible( true );
		return $m->invoke( $this->gateway(), $order );
	}

	// ── get_total_sats: spot quote reuse, refresh, failure ──────────────

	public function test_fresh_spot_quote_is_reused_without_rate_lookup(): void {
		$this->stubGatewayBaseline();
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'wp_remote_get' )->never();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_spot_total' => '5000',
				'_cashu_spot_time'  => (string) ( time() - 60 ),
			)
		);

		$this->assertSame( 5000, $this->totalSats( $order ) );
	}

	public function test_expired_spot_quote_requotes_and_persists(): void {
		$this->stubGatewayBaseline();
		// Cached BTC spot price: $100k/BTC, so $10 → 10,000 sats.
		Functions\when( 'get_transient' )->alias(
			static fn ( string $key ) => 0 === strpos( $key, 'cashu_btc_spot_cb_' ) ? 100000.0 : false
		);

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_spot_total' => '9000',
				'_cashu_spot_time'  => (string) ( time() - CashuGateway::QUOTE_EXPIRY_SECS - 10 ),
			)
		);
		$order->shouldReceive( 'get_total' )->andReturn( '10.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		$this->assertSame( 10000, $this->totalSats( $order ) );
		$this->assertSame( 10000, $order->get_meta( '_cashu_spot_total' ) );
		$this->assertSame( 'coinbase_spot', $order->get_meta( '_cashu_spot_source' ) );
	}

	public function test_sub_sat_totals_round_up_so_merchant_never_underinvoices(): void {
		$this->stubGatewayBaseline();
		Functions\when( 'get_transient' )->alias(
			static fn ( string $key ) => 0 === strpos( $key, 'cashu_btc_spot_cb_' ) ? 100000.0 : false
		);

		$order = $this->mockOrder( 42 );
		// $0.011 at $100k/BTC = 11.0000000001-ish sats → must ceil, never floor.
		$order->shouldReceive( 'get_total' )->andReturn( '0.0111' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		$this->assertSame( 12, $this->totalSats( $order ) );
	}

	public function test_unobtainable_rate_throws_price_quote_error(): void {
		$this->stubGatewayBaseline();
		// No cached price, both rate APIs down. The CoinGecko fallback
		// builds its URL via add_query_arg before the request fails.
		Functions\when( 'add_query_arg' )->alias(
			static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias( static fn ( $t ): bool => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'down' ) );

		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '10.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );

		$this->expectException( \RuntimeException::class );

		$this->totalSats( $order );
	}

	// ── process_payment outcomes ─────────────────────────────────────────

	public function test_missing_order_returns_failure(): void {
		$this->stubGatewayBaseline();
		Functions\when( 'wc_get_order' )->justReturn( false );

		$this->assertSame( array( 'result' => 'failure' ), $this->gateway()->process_payment( 42 ) );
	}

	public function test_zero_total_completes_payment_without_mint_round_trip(): void {
		$this->stubGatewayBaseline();

		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '0.00' );
		$order->shouldReceive( 'payment_complete' )->once()->andReturn( true );
		$order->shouldReceive( 'get_checkout_payment_url' )->with( true )->andReturn( 'https://shop/pay/42' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$cart = \Mockery::mock();
		$cart->shouldReceive( 'empty_cart' )->once();
		$wc       = new \stdClass();
		$wc->cart = $cart;
		Functions\when( 'WC' )->justReturn( $wc );

		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		$result = $this->gateway()->process_payment( 42 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://shop/pay/42', $result['redirect'] );
	}

	public function test_setup_failure_adds_customer_notice_and_keeps_cart(): void {
		$this->stubGatewayBaseline();
		$this->setUpFakeWpdb();

		// Total > 0 but no rate available anywhere: setup throws inside
		// get_total_sats and process_payment must fail soft — notice added,
		// cart NOT emptied, customer stays on checkout.
		Functions\when( 'add_query_arg' )->alias(
			static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias( static fn ( $t ): bool => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'down' ) );

		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( '10.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'update_status' )->andReturn( true );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$cart = \Mockery::mock();
		$cart->shouldReceive( 'empty_cart' )->never();
		$wc       = new \stdClass();
		$wc->cart = $cart;
		Functions\when( 'WC' )->justReturn( $wc );

		$notices = array();
		Functions\when( 'wc_add_notice' )->alias(
			static function ( string $msg, string $type = '' ) use ( &$notices ): void {
				$notices[] = array( $msg, $type );
			}
		);

		$result = $this->gateway()->process_payment( 42 );

		$this->assertSame( array( 'result' => 'failure' ), $result );
		$this->assertCount( 1, $notices );
		$this->assertSame( 'error', $notices[0][1] );
	}
}
