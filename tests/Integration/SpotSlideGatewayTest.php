<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Helpers\CashuPaths;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * Gateway wiring for the late-settlement watch: a lapsed 15-minute spot
 * window slides (keeps the standing quote) instead of repricing when the
 * customer's mint quote is still payable and drift stays inside tolerance,
 * and new mint quotes are stamped with a creation time so the payable
 * window can be computed later.
 */
final class SpotSlideGatewayTest extends IntegrationTestCase {

	private const MINT = 'https://mint.example';

	/**
	 * BOLT11-shaped lightning address: LightningAddress::get_invoice
	 * returns it verbatim, keeping LNURL HTTP out of these tests.
	 */
	private const BOLT11 = 'lnbc50u1pfakeinvoiceforrotationtests';

	private function stubGatewayBaseline(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				$values = array(
					'cashu_lightning_address' => self::BOLT11,
					'cashu_trusted_mint'      => self::MINT,
					'cashu_paths'             => CashuPaths::DEFAULT_PATHS,
				);
				return $values[ $key ] ?? $default;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_x' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'WC' )->alias(
			static function (): \stdClass {
				$wc       = new \stdClass();
				$wc->cart = null;
				return $wc;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( static fn ( $t ): bool => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( static fn ( $r ): int => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( static fn ( $r ): string => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	private function totalSats( $order ): int {
		$m = new \ReflectionMethod( CashuGateway::class, 'get_total_sats' );
		$m->setAccessible( true );
		$gw          = new CashuGateway();
		$gw->enabled = 'yes';
		return $m->invoke( $gw, $order );
	}

	public function test_lapsed_window_slides_and_keeps_standing_total(): void {
		$this->stubGatewayBaseline();
		// Price transient primed: $100.00 at 100k = 100_000 sats fresh.
		Functions\when( 'get_transient' )->alias(
			static fn ( string $key ) => str_starts_with( $key, 'cashu_btc_spot_cb_' ) ? 100000.0 : false
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_spot_total'         => '100000',
				'_cashu_spot_time'          => (string) ( time() - 1000 ),
				'_cashu_mint_quote_id'      => 'mq1',
				'_cashu_mint_quote_expiry'  => (string) ( time() + HOUR_IN_SECONDS ),
				'_cashu_mint_quote_created' => (string) ( time() - 1000 ),
			)
		);
		$order->shouldReceive( 'get_total' )->andReturn( '100.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		// No re-quote note may be written on a slide.
		$order->shouldReceive( 'add_order_note' )->never();

		$this->assertSame( 100000, $this->totalSats( $order ) );
		$this->assertGreaterThan( time() - 5, (int) $order->get_meta( '_cashu_spot_time' ) );
	}

	public function test_lapsed_window_beyond_band_reprices_with_note(): void {
		$this->stubGatewayBaseline();
		Functions\when( 'get_transient' )->alias(
			static fn ( string $key ) => str_starts_with( $key, 'cashu_btc_spot_cb_' ) ? 100000.0 : false
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_spot_total'         => '99000',
				'_cashu_spot_time'          => (string) ( time() - 1000 ),
				'_cashu_mint_quote_id'      => 'mq1',
				'_cashu_mint_quote_expiry'  => (string) ( time() + HOUR_IN_SECONDS ),
				'_cashu_mint_quote_created' => (string) ( time() - 1000 ),
			)
		);
		$order->shouldReceive( 'get_total' )->andReturn( '100.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		$this->assertSame( 100000, $this->totalSats( $order ) );
		$this->assertSame( '100000', (string) $order->get_meta( '_cashu_spot_total' ) );
	}

	public function test_new_mint_quote_is_stamped_with_creation_time(): void {
		$this->stubGatewayBaseline();
		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode(
					array(
						'quote'   => 'mq_new',
						'request' => 'lnbc1fakerequest',
						'expiry'  => time() + 1200,
					)
				),
			)
		);

		$m = new \ReflectionMethod( CashuGateway::class, 'ensure_mint_quote_for_order' );
		$m->setAccessible( true );
		$gw          = new CashuGateway();
		$gw->enabled = 'yes';
		$m->invoke( $gw, $order, 5000 );

		$this->assertGreaterThan( time() - 5, (int) $order->get_meta( '_cashu_mint_quote_created' ) );
	}
}
