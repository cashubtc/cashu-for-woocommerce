<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * Covers CashuGateway::request_melt_bolt11. Pins the JSON body shape sent to
 * the mint (endpoint, quote id, proofs array with coerced int amounts), the
 * response-code branching (2xx → decoded array; error → RuntimeException),
 * and the per-call mint_url override that PayController plumbs after the H1
 * fix in 61c9936 so an admin-side mint change can't redirect the melt.
 */
final class RequestMeltBolt11Test extends IntegrationTestCase {

	/**
	 * Builds a CashuGateway without firing the (settings-loading,
	 * action-registering) parent constructor. request_melt_bolt11 only reads
	 * $this->trusted_mint and otherwise calls free functions, so this skips
	 * everything the gateway plumbing wants.
	 */
	private function gateway( string $trusted_mint = 'https://default-mint.example' ): CashuGateway {
		$gateway = ( new \ReflectionClass( CashuGateway::class ) )->newInstanceWithoutConstructor();
		( new \ReflectionProperty( CashuGateway::class, 'trusted_mint' ) )
			->setValue( $gateway, $trusted_mint );
		return $gateway;
	}

	private function stubResponseHelpers(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( fn( $r ) => $r['response']['code'] ?? 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( fn( $r ) => $r['body'] ?? '' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	public function test_posts_to_per_call_mint_url_not_trusted_default(): void {
		$this->stubResponseHelpers();

		$captured = null;
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url, array $args ) use ( &$captured ) {
					$captured = compact( 'url', 'args' );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => json_encode( array( 'state' => 'PAID', 'quote' => 'q_123' ) ),
					);
				}
			);

		$result = $this->gateway( 'https://default-mint.example' )
			->request_melt_bolt11( 'q_123', array(), 'https://order-mint.example' );

		$this->assertSame( 'https://order-mint.example/v1/melt/bolt11', $captured['url'] );
		$this->assertSame( 'PAID', $result['state'] );
	}

	public function test_falls_back_to_trusted_mint_when_url_empty(): void {
		$this->stubResponseHelpers();

		$captured_url = null;
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url, array $args ) use ( &$captured_url ) {
					$captured_url = $url;
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => json_encode( array( 'state' => 'PAID' ) ),
					);
				}
			);

		$this->gateway( 'https://default-mint.example/' )
			->request_melt_bolt11( 'q_123', array() );

		// rtrim should strip the trailing slash on $trusted_mint before joining.
		$this->assertSame( 'https://default-mint.example/v1/melt/bolt11', $captured_url );
	}

	public function test_serialises_proofs_with_int_amounts(): void {
		$this->stubResponseHelpers();

		$captured_body = null;
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url, array $args ) use ( &$captured_body ) {
					$captured_body = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => json_encode( array( 'state' => 'PAID' ) ),
					);
				}
			);

		$proofs = array(
			array(
				'id'     => 'keyset_a',
				'amount' => '4',       // decimal-string
				'secret' => 'sec1',
				'C'      => 'pub1',
			),
			array(
				'id'      => 'keyset_a',
				'amount'  => 8,        // already int
				'secret'  => 'sec2',
				'C'       => 'pub2',
				'witness' => 'wit',    // optional, only sent when set
			),
		);

		$this->gateway()->request_melt_bolt11( 'q_123', $proofs, 'https://m.example' );

		$this->assertSame( 'q_123', $captured_body['quote'] );
		$this->assertSame( 4, $captured_body['inputs'][0]['amount'] );
		$this->assertSame( 8, $captured_body['inputs'][1]['amount'] );
		$this->assertArrayNotHasKey( 'witness', $captured_body['inputs'][0] );
		$this->assertSame( 'wit', $captured_body['inputs'][1]['witness'] );
	}

	public function test_non_numeric_amount_coerces_to_zero(): void {
		// M8 in PLAN: the gateway helper silently coerces malformed amounts
		// to 0. PayController rejects them upstream first, but we pin the
		// fallback behaviour here so a future tightening is intentional.
		$this->stubResponseHelpers();

		$captured_body = null;
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( string $url, array $args ) use ( &$captured_body ) {
					$captured_body = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => json_encode( array( 'state' => 'PAID' ) ),
					);
				}
			);

		$proofs = array(
			array( 'id' => 'k', 'amount' => 'totally-not-a-number', 'secret' => 's', 'C' => 'c' ),
		);

		$this->gateway()->request_melt_bolt11( 'q_123', $proofs, 'https://m.example' );

		$this->assertSame( 0, $captured_body['inputs'][0]['amount'] );
	}

	public function test_throws_on_non_2xx_response(): void {
		$this->stubResponseHelpers();

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn(
				array(
					'response' => array( 'code' => 500 ),
					'body'     => 'mint exploded',
				)
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/HTTP 500/' );

		$this->gateway()->request_melt_bolt11( 'q_123', array(), 'https://m.example' );
	}

	public function test_throws_on_wp_error(): void {
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$wp_error = new class() {
			public function get_error_message(): string {
				return 'connection refused';
			}
		};
		Functions\expect( 'wp_remote_post' )->once()->andReturn( $wp_error );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/connection refused/' );

		$this->gateway()->request_melt_bolt11( 'q_123', array(), 'https://m.example' );
	}

	public function test_throws_on_non_json_body(): void {
		$this->stubResponseHelpers();

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => 'not json at all',
				)
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/not JSON/' );

		$this->gateway()->request_melt_bolt11( 'q_123', array(), 'https://m.example' );
	}
}
