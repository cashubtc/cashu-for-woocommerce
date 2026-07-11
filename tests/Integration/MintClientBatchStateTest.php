<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\MintClient;
use Cashu\WC\Tests\IntegrationTestCase;

final class MintClientBatchStateTest extends IntegrationTestCase {

	private const MINT = 'https://mint.example';

	private array $transients = array();

	private function stubBaseline(): void {
		$this->transients = array();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( static fn ( $t ): bool => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( static fn ( $r ): int => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( static fn ( $r ): string => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );
		Functions\when( 'get_transient' )->alias(
			fn ( string $k ) => $this->transients[ $k ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $k, $v ): bool {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
	}

	public function test_batch_endpoint_maps_states_by_quote_id(): void {
		$this->stubBaseline();
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode(
					array(
						array(
							'quote' => 'a',
							'state' => 'PAID',
						),
						array(
							'quote' => 'b',
							'state' => 'UNPAID',
						),
					)
				),
			)
		);
		Functions\expect( 'wp_remote_get' )->never();

		$states = MintClient::mint_quote_states( self::MINT, array( 'a', 'b' ) );

		$this->assertSame(
			array(
				'a' => 'PAID',
				'b' => 'UNPAID',
			),
			$states
		);
	}

	public function test_unsupported_batch_flags_mint_and_falls_back_per_quote(): void {
		$this->stubBaseline();
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
			)
		);
		Functions\expect( 'wp_remote_get' )->times( 4 )->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'state' => 'PAID' ) ),
			)
		);

		$states = MintClient::mint_quote_states( self::MINT, array( 'a', 'b' ) );
		$this->assertSame(
			array(
				'a' => 'PAID',
				'b' => 'PAID',
			),
			$states
		);

		// Second call: the cached flag skips the batch endpoint entirely,
		// so wp_remote_post has fired exactly once across both calls.
		MintClient::mint_quote_states( self::MINT, array( 'a', 'b' ) );
	}

	public function test_empty_inputs_return_empty(): void {
		$this->stubBaseline();
		$this->assertSame( array(), MintClient::mint_quote_states( '', array( 'a' ) ) );
		$this->assertSame( array(), MintClient::mint_quote_states( self::MINT, array() ) );
	}
}
