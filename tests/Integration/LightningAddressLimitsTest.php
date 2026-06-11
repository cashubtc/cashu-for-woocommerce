<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\AmountLimitException;
use Cashu\WC\Helpers\LightningAddress;
use Cashu\WC\Tests\IntegrationTestCase;

final class LightningAddressLimitsTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ) => trim( (string) $s ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $r ) => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : ''
		);
	}

	private function metadataResponse( array $overrides = array() ): array {
		$body = array_merge(
			array(
				'tag'         => 'payRequest',
				'callback'    => 'https://ln.example/cb',
				'minSendable' => 1000000,   // 1,000 sat
				'maxSendable' => 100000000, // 100,000 sat
			),
			$overrides
		);
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( $body ),
		);
	}

	public function test_amount_below_min_sendable_throws_typed_exception(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->metadataResponse() );

		$this->expectException( AmountLimitException::class );
		$this->expectExceptionMessage( 'outside limits' );

		LightningAddress::get_invoice( 'me@example.com', 999 );
	}

	public function test_amount_above_max_sendable_throws_typed_exception(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->metadataResponse() );

		$this->expectException( AmountLimitException::class );

		LightningAddress::get_invoice( 'me@example.com', 100001 );
	}

	public function test_in_range_amount_proceeds_to_callback(): void {
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturnUsing(
				function ( string $url ): array {
					if ( false !== strpos( $url, '.well-known' ) ) {
						return $this->metadataResponse();
					}
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"pr":"lnbc50u1pexample"}',
					);
				}
			);

		$this->assertSame( 'lnbc50u1pexample', LightningAddress::get_invoice( 'me@example.com', 5000 ) );
	}

	public function test_missing_bounds_do_not_block_the_amount(): void {
		// Absent / zero sendable bounds mean "unbounded" — a metadata body
		// without them must not be misread as min 0 / max 0.
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturnUsing(
				function ( string $url ): array {
					if ( false !== strpos( $url, '.well-known' ) ) {
						return $this->metadataResponse( array( 'minSendable' => 0, 'maxSendable' => null ) );
					}
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"pr":"lnbc1pexample"}',
					);
				}
			);

		$this->assertSame( 'lnbc1pexample', LightningAddress::get_invoice( 'me@example.com', 123456789 ) );
	}

	public function test_callback_error_reason_is_surfaced(): void {
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturnUsing(
				function ( string $url ): array {
					if ( false !== strpos( $url, '.well-known' ) ) {
						return $this->metadataResponse();
					}
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"status":"ERROR","reason":"Amount exceeds daily quota"}',
					);
				}
			);

		try {
			LightningAddress::get_invoice( 'me@example.com', 5000 );
			$this->fail( 'Expected RuntimeException' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Amount exceeds daily quota', $e->getMessage() );
		}
	}
}
