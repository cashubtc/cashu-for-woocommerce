<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\CashuHelper;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * fiatToSats rate sourcing: Coinbase first, CoinGecko on failure, hard
 * error when neither yields a positive price.
 */
final class FiatToSatsFallbackTest extends IntegrationTestCase {

	private function stubBaseline(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( static fn ( $t ): bool => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( static fn ( $r ): int => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( static fn ( $r ): string => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
	}

	private function coinbaseResponse( string $amount, string $currency = 'USD' ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode(
				array(
					'data' => array(
						'amount'   => $amount,
						'currency' => $currency,
					),
				)
			),
		);
	}

	public function test_uses_coinbase_when_available(): void {
		$this->stubBaseline();
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->coinbaseResponse( '100000' ) );

		$quote = CashuHelper::fiatToSats( 10.0, 'usd' );

		$this->assertSame( 10000, $quote['sats'] );
		$this->assertSame( 'coinbase_spot', $quote['source'] );
		$this->assertSame( 100000.0, $quote['btc_price'] );
	}

	public function test_falls_back_to_coingecko_when_coinbase_fails(): void {
		$this->stubBaseline();
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturnUsing(
				static function ( string $url ): array {
					if ( false !== strpos( $url, 'coinbase' ) ) {
						return array(
							'response' => array( 'code' => 500 ),
							'body'     => '',
						);
					}
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => (string) json_encode( array( 'bitcoin' => array( 'usd' => 100000 ) ) ),
					);
				}
			);

		$quote = CashuHelper::fiatToSats( 10.0, 'USD' );

		$this->assertSame( 10000, $quote['sats'] );
		$this->assertSame( 'coingecko_simple_price', $quote['source'] );
	}

	public function test_falls_back_when_coinbase_returns_wrong_currency(): void {
		// Coinbase replying with the wrong currency must not silently price
		// a GBP order in USD — it falls through to CoinGecko.
		$this->stubBaseline();
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturnUsing(
				function ( string $url ): array {
					if ( false !== strpos( $url, 'coinbase' ) ) {
						return $this->coinbaseResponse( '100000', 'USD' ); // asked for GBP
					}
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => (string) json_encode( array( 'bitcoin' => array( 'gbp' => 80000 ) ) ),
					);
				}
			);

		$quote = CashuHelper::fiatToSats( 8.0, 'GBP' );

		$this->assertSame( 'coingecko_simple_price', $quote['source'] );
		$this->assertSame( 10000, $quote['sats'] );
	}

	public function test_throws_when_both_sources_fail(): void {
		$this->stubBaseline();
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'down' ) );

		$this->expectException( \RuntimeException::class );

		CashuHelper::fiatToSats( 10.0, 'USD' );
	}

	public function test_zero_amount_short_circuits_without_network(): void {
		$this->stubBaseline();
		Functions\expect( 'wp_remote_get' )->never();

		$quote = CashuHelper::fiatToSats( 0.0, 'USD' );

		$this->assertSame( 0, $quote['sats'] );
		$this->assertSame( 'none', $quote['source'] );
	}

	public function test_rejects_non_positive_coingecko_price(): void {
		// A 0 price would make every order "free" — must throw, not divide.
		$this->stubBaseline();
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturnUsing(
				static function ( string $url ): array {
					if ( false !== strpos( $url, 'coinbase' ) ) {
						return array(
							'response' => array( 'code' => 500 ),
							'body'     => '',
						);
					}
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => (string) json_encode( array( 'bitcoin' => array( 'usd' => 0 ) ) ),
					);
				}
			);

		$this->expectException( \RuntimeException::class );

		CashuHelper::fiatToSats( 10.0, 'USD' );
	}
}
