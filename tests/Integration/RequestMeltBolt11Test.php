<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\MintClient;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * Covers MintClient::melt. Pins the JSON body shape sent to the mint
 * (endpoint, quote id, proofs array with coerced int amounts) and the
 * response-code branching (2xx → decoded array; error → RuntimeException).
 * The mint URL is always explicit so an admin-side mint change can never
 * redirect a melt to a host that doesn't know the quote.
 */
final class RequestMeltBolt11Test extends IntegrationTestCase {

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

	public function test_posts_to_the_given_mint_url(): void {
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

		$result = MintClient::melt( 'https://order-mint.example', 'q_123', array() );

		$this->assertSame( 'https://order-mint.example/v1/melt/bolt11', $captured['url'] );
		$this->assertSame( 'PAID', $result['state'] );
	}

	public function test_trims_trailing_slash_from_mint_url(): void {
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

		MintClient::melt( 'https://default-mint.example/', 'q_123', array() );

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

		MintClient::melt( 'https://m.example', 'q_123', $proofs );

		$this->assertSame( 'q_123', $captured_body['quote'] );
		$this->assertSame( 4, $captured_body['inputs'][0]['amount'] );
		$this->assertSame( 8, $captured_body['inputs'][1]['amount'] );
		$this->assertArrayNotHasKey( 'witness', $captured_body['inputs'][0] );
		$this->assertSame( 'wit', $captured_body['inputs'][1]['witness'] );
	}

	public function test_non_numeric_amount_coerces_to_zero(): void {
		// The client silently coerces malformed amounts to 0. PayController
		// rejects them upstream first, but we pin the fallback behaviour
		// here so a future tightening is intentional.
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

		MintClient::melt( 'https://m.example', 'q_123', $proofs );

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

		MintClient::melt( 'https://m.example', 'q_123', array() );
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

		MintClient::melt( 'https://m.example', 'q_123', array() );
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

		MintClient::melt( 'https://m.example', 'q_123', array() );
	}
}
