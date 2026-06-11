<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Helpers\AmountLimitException;
use Cashu\WC\Helpers\CashuPaths;
use Cashu\WC\Helpers\MintLimits;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * The cart-total limits gate in is_available(): hide the gateway only when
 * fresh snapshot data clearly excludes the amount; fail open on everything
 * else. Companion to IsAvailableTest (config gates) and MintLimitsTest
 * (range math).
 */
final class IsAvailableLimitsTest extends IntegrationTestCase {

	private array $optionStore = array();

	protected function setUp(): void {
		parent::setUp();
		$this->optionStore = array(
			'cashu_lightning_address' => 'me@example.com',
			'cashu_trusted_mint'      => 'https://mint.example/Bitcoin',
			'cashu_paths'             => CashuPaths::DEFAULT_PATHS,
		);

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_x' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'absint' )->alias( static fn ( $n ) => abs( (int) $n ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return $this->optionStore[ $key ] ?? $default;
			}
		);
	}

	/** Define WC() with a cart returning the given total. */
	private function stubCart( float $total ): void {
		$cart = new class() {
			public float $total = 0;
			public function get_total( string $context = 'view' ): float {
				return $this->total;
			}
		};
		$cart->total = $total;

		$wc       = new \stdClass();
		$wc->cart = $cart;
		Functions\when( 'WC' )->justReturn( $wc );
	}

	/** Seed a fresh limits snapshot for the configured mint/address. */
	private function seedLimits( array $mint = array(), array $lnurl = array() ): void {
		$this->optionStore[ MintLimits::OPTION ] = array(
			'mint'  => array_merge(
				array(
					'url'        => 'https://mint.example/Bitcoin',
					'mint_min'   => null,
					'mint_max'   => null,
					'melt_min'   => 100,
					'melt_max'   => 50000,
					'fetched_at' => time(),
				),
				$mint
			),
			'lnurl' => array_merge(
				array(
					'address'    => 'me@example.com',
					'min'        => 1,
					'max'        => 500000,
					'fetched_at' => time(),
				),
				$lnurl
			),
		);
	}

	/** Cache a BTC spot price so fiatToSats converts without network. */
	private function stubBtcPrice( float $usd_per_btc ): void {
		Functions\when( 'get_transient' )->alias(
			static fn ( string $key ) => 0 === strpos( $key, 'cashu_btc_spot_cb_' ) ? $usd_per_btc : false
		);
	}

	private function gateway(): CashuGateway {
		$gw          = new CashuGateway();
		$gw->enabled = 'yes';
		return $gw;
	}

	public function test_hides_gateway_when_cart_total_exceeds_limits(): void {
		// $100 at $100k/BTC = 100,000 sats > melt_max 50,000.
		$this->seedLimits();
		$this->stubCart( 100.0 );
		$this->stubBtcPrice( 100000.0 );

		$this->assertFalse( $this->gateway()->is_available() );
	}

	public function test_shows_gateway_when_cart_total_in_range(): void {
		// $10 at $100k/BTC = 10,000 sats, inside 100–50,000.
		$this->seedLimits();
		$this->stubCart( 10.0 );
		$this->stubBtcPrice( 100000.0 );

		$this->assertTrue( $this->gateway()->is_available() );
	}

	public function test_fails_open_when_rate_lookup_fails(): void {
		// No cached price and both rate APIs unreachable: fiatToSats throws,
		// is_available() must swallow it and keep the gateway visible.
		$this->seedLimits();
		$this->stubCart( 100.0 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'down' ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );

		$this->assertTrue( $this->gateway()->is_available() );
	}

	public function test_fails_open_when_no_limits_snapshot(): void {
		$this->stubCart( 100.0 );
		$this->stubBtcPrice( 100000.0 );

		$this->assertTrue( $this->gateway()->is_available() );
	}

	public function test_skips_check_in_admin_context(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->seedLimits();
		$this->stubCart( 100.0 );
		$this->stubBtcPrice( 100000.0 );

		$this->assertTrue( $this->gateway()->is_available() );
	}

	public function test_classify_setup_error_maps_limit_exception_to_amount_message(): void {
		$gw     = $this->gateway();
		$method = new \ReflectionMethod( CashuGateway::class, 'classify_setup_error' );
		$method->setAccessible( true );

		$limit_msg = $method->invoke( $gw, new AmountLimitException( 'Lightning address amount outside limits: 5 sat is not within 1000000-100000000 msat sendable bounds.' ) );
		$this->assertStringContainsString( 'outside the amounts', $limit_msg );
		$this->assertStringNotContainsString( 'reload', strtolower( $limit_msg ) );

		// Same message text as a plain RuntimeException must NOT match the
		// limit branch — typing, not string sniffing, drives it. It contains
		// "lightning address", so it falls to the LN-address branch.
		$generic = $method->invoke( $gw, new \RuntimeException( 'Lightning address amount outside limits: …' ) );
		$this->assertStringNotContainsString( 'outside the amounts', $generic );
	}
}
