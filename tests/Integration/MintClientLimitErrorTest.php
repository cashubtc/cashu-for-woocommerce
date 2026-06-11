<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\AmountLimitException;
use Cashu\WC\Helpers\MintClient;
use Cashu\WC\Tests\IntegrationTestCase;

final class MintClientLimitErrorTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ) => trim( (string) $s ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $r ) => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : ''
		);
	}

	// ── is_limit_error_body ──────────────────────────────────────────────

	public function test_error_code_11006_is_a_limit_error(): void {
		$this->assertTrue(
			MintClient::is_limit_error_body( '{"detail":"amount out of range","code":11006}' )
		);
	}

	public function test_detail_string_signals_are_limit_errors(): void {
		$this->assertTrue( MintClient::is_limit_error_body( '{"detail":"amount must be at least 100 sat"}' ) );
		$this->assertTrue( MintClient::is_limit_error_body( '{"detail":"Melt amount exceeds backend limit"}' ) );
		$this->assertTrue( MintClient::is_limit_error_body( '{"error":"amount outside of limit range"}' ) );
	}

	public function test_unrelated_errors_are_not_limit_errors(): void {
		$this->assertFalse( MintClient::is_limit_error_body( '{"detail":"invalid payment request","code":20002}' ) );
		$this->assertFalse( MintClient::is_limit_error_body( '{"detail":"keyset inactive"}' ) );
		$this->assertFalse( MintClient::is_limit_error_body( 'Bad Gateway' ) );
		$this->assertFalse( MintClient::is_limit_error_body( '' ) );
	}

	// ── quote requests surface the body and type the limit failure ──────

	public function test_mint_quote_throws_typed_exception_on_limit_rejection(): void {
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 400 ),
				'body'     => '{"detail":"amount out of range","code":11006}',
			)
		);

		$this->expectException( AmountLimitException::class );
		$this->expectExceptionMessage( 'outside limits' );

		MintClient::request_mint_quote( 'https://mint.example', 999999999 );
	}

	public function test_melt_quote_error_includes_mint_detail_in_message(): void {
		// Non-limit failures stay RuntimeException but must now carry the
		// mint's error body — "HTTP 400" alone is undiagnosable in the log.
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 400 ),
				'body'     => '{"detail":"keyset inactive","code":12002}',
			)
		);

		try {
			MintClient::request_melt_quote( 'https://mint.example', 'lnbc1...' );
			$this->fail( 'Expected RuntimeException' );
		} catch ( AmountLimitException $e ) {
			$this->fail( 'Non-limit error must not be typed as AmountLimitException' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'keyset inactive', $e->getMessage() );
			$this->assertStringContainsString( 'HTTP 400', $e->getMessage() );
		}
	}

	public function test_melt_quote_throws_typed_exception_on_limit_rejection(): void {
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 400 ),
				'body'     => '{"detail":"amount must be at least 100 sat","code":11006}',
			)
		);

		$this->expectException( AmountLimitException::class );

		MintClient::request_melt_quote( 'https://mint.example', 'lnbc1...' );
	}
}
