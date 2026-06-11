<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\LightningAddress;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * get_invoice's input/transport error ladder and the LUD-12 comment
 * handling. Companion to LightningAddressLimitsTest (sendable bounds).
 */
final class LightningAddressErrorPathsTest extends IntegrationTestCase {

	private function stubBaseline(): void {
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ) => trim( (string) $s ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $t ): bool => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( static fn ( $r ): int => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( static fn ( $r ): string => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );
	}

	private function metadata( array $overrides = array() ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode(
				array_merge(
					array(
						'tag'         => 'payRequest',
						'callback'    => 'https://ln.example/cb',
						'minSendable' => 1000,
						'maxSendable' => 100000000000,
					),
					$overrides
				)
			),
		);
	}

	public function test_testnet_and_regtest_bolt11_pass_through_verbatim(): void {
		$this->stubBaseline();
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( 'lntb500u1pfake', LightningAddress::get_invoice( 'lntb500u1pfake', 50000 ) );
		$this->assertSame( 'lnbcrt500u1pfake', LightningAddress::get_invoice( 'lnbcrt500u1pfake', 50000 ) );
	}

	public function test_rejects_address_without_at_sign(): void {
		$this->stubBaseline();
		Functions\expect( 'wp_remote_get' )->never();

		$this->expectExceptionMessage( 'Invalid Lightning address' );
		LightningAddress::get_invoice( 'not-an-address', 1000 );
	}

	public function test_metadata_transport_failure(): void {
		$this->stubBaseline();
		Functions\expect( 'wp_remote_get' )->once()->andReturn( new \WP_Error( 'http_request_failed', 'down' ) );

		$this->expectExceptionMessage( 'Failed to fetch LNURL metadata' );
		LightningAddress::get_invoice( 'me@example.com', 1000 );
	}

	public function test_metadata_without_callback_is_invalid(): void {
		$this->stubBaseline();
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"tag":"payRequest"}',
			)
		);

		$this->expectExceptionMessage( 'Invalid LNURL metadata response' );
		LightningAddress::get_invoice( 'me@example.com', 1000 );
	}

	public function test_invoice_transport_failure(): void {
		$this->stubBaseline();
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturnUsing(
				function ( string $url ) {
					return false !== strpos( $url, '.well-known' )
						? $this->metadata()
						: new \WP_Error( 'http_request_failed', 'down' );
				}
			);

		$this->expectExceptionMessage( 'Failed to request invoice' );
		LightningAddress::get_invoice( 'me@example.com', 1000 );
	}

	public function test_comment_sent_truncated_to_provider_limit(): void {
		// LUD-12: commentAllowed caps the comment length; truncate rather
		// than fail the invoice over an over-long order note.
		$this->stubBaseline();
		$callback_url = '';
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturnUsing(
				function ( string $url ) use ( &$callback_url ) {
					if ( false !== strpos( $url, '.well-known' ) ) {
						return $this->metadata( array( 'commentAllowed' => 10 ) );
					}
					$callback_url = $url;
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"pr":"lnbc10n1pfake"}',
					);
				}
			);

		$invoice = LightningAddress::get_invoice( 'me@example.com', 1000, 'Order: #4242 with a long tail' );

		$this->assertSame( 'lnbc10n1pfake', $invoice );
		parse_str( (string) parse_url( $callback_url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'Order: #42', $query['comment'] );
		$this->assertSame( '1000000', $query['amount'] ); // msat
	}

	public function test_comment_omitted_when_provider_disallows(): void {
		$this->stubBaseline();
		$callback_url = '';
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturnUsing(
				function ( string $url ) use ( &$callback_url ) {
					if ( false !== strpos( $url, '.well-known' ) ) {
						return $this->metadata(); // no commentAllowed
					}
					$callback_url = $url;
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"pr":"lnbc10n1pfake"}',
					);
				}
			);

		LightningAddress::get_invoice( 'me@example.com', 1000, 'Order: #4242' );

		$this->assertStringNotContainsString( 'comment', $callback_url );
	}
}
