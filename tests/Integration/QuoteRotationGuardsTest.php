<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Helpers\CashuPaths;
use Cashu\WC\Tests\IntegrationTestCase;
use ReflectionMethod;

/**
 * The funds-safety invariants of quote rotation. A melt/mint quote with
 * value bound to it (mint says PAID / PENDING / ISSUED, or the mint can't
 * be reached to ask) must NEVER be rotated — rotating orphans the
 * customer's payment. Rotation is only legal for a quote the issuing mint
 * positively reports UNPAID and that has expired, and even then the old
 * quote must land in the archive meta first so it stays traceable.
 */
final class QuoteRotationGuardsTest extends IntegrationTestCase {

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

	private function gateway(): CashuGateway {
		$gw          = new CashuGateway();
		$gw->enabled = 'yes';
		return $gw;
	}

	private function ensureMelt( $order, int $sats ): void {
		$m = new ReflectionMethod( CashuGateway::class, 'ensure_melt_quote_for_order' );
		$m->setAccessible( true );
		$m->invoke( $this->gateway(), $order, $sats );
	}

	private function ensureMint( $order, int $sats ): void {
		$m = new ReflectionMethod( CashuGateway::class, 'ensure_mint_quote_for_order' );
		$m->setAccessible( true );
		$m->invoke( $this->gateway(), $order, $sats );
	}

	/** Mint melt-quote state probe response. */
	private function meltStateResponse( string $state ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( 'state' => $state ) ),
		);
	}

	// ── melt leg: never rotate a quote that may carry value ─────────────

	public function test_melt_pending_marker_blocks_all_quote_mutation(): void {
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q_inflight',
				'_cashu_melt_quote_id'         => 'q_old',
			)
		);
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		$this->ensureMelt( $order, 5000 );

		$this->assertSame( 'q_old', $order->get_meta( '_cashu_melt_quote_id' ) );
	}

	/** @dataProvider preservedMeltStates */
	public function test_melt_quote_preserved_when_mint_says( string $state ): void {
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id'     => 'q_old',
				'_cashu_melt_quote_expiry' => (string) ( time() - 600 ), // expired — must still not rotate
				'_cashu_melt_mint'         => self::MINT,
			)
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->meltStateResponse( $state ) );
		Functions\expect( 'wp_remote_post' )->never();

		$this->ensureMelt( $order, 5000 );

		$this->assertSame( 'q_old', $order->get_meta( '_cashu_melt_quote_id' ) );
		$this->assertSame( '', $order->get_meta( '_cashu_archived_melt_quotes' ) );
	}

	public static function preservedMeltStates(): array {
		return array(
			'PAID — already settled'   => array( 'PAID' ),
			'PENDING — mid LN payment' => array( 'PENDING' ),
		);
	}

	public function test_melt_quote_preserved_when_mint_unreachable(): void {
		// Unknown state must read as "possibly paid": rotating on a network
		// blip would orphan a settled payment.
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id'     => 'q_old',
				'_cashu_melt_quote_expiry' => (string) ( time() - 600 ),
				'_cashu_melt_mint'         => self::MINT,
			)
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( new \WP_Error( 'http_request_failed', 'timeout' ) );
		Functions\expect( 'wp_remote_post' )->never();

		$this->ensureMelt( $order, 5000 );

		$this->assertSame( 'q_old', $order->get_meta( '_cashu_melt_quote_id' ) );
	}

	public function test_melt_quote_preserved_when_unpaid_but_not_expired(): void {
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id'     => 'q_old',
				'_cashu_melt_quote_expiry' => (string) ( time() + 600 ),
				'_cashu_melt_mint'         => self::MINT,
			)
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->meltStateResponse( 'UNPAID' ) );
		Functions\expect( 'wp_remote_post' )->never();

		$this->ensureMelt( $order, 5000 );

		$this->assertSame( 'q_old', $order->get_meta( '_cashu_melt_quote_id' ) );
	}

	public function test_melt_unpaid_expired_archives_then_rotates(): void {
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id'      => 'q_old',
				'_cashu_melt_quote_expiry'  => (string) ( time() - 600 ),
				'_cashu_melt_mint'          => self::MINT,
				'_cashu_melt_quote_request' => 'lnbc_old_invoice',
			)
		);
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->meltStateResponse( 'UNPAID' ) );
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode(
					array(
						'quote'       => 'q_new',
						'expiry'      => time() + 900,
						'amount'      => 5000,
						'fee_reserve' => 10,
						'unit'        => 'sat',
					)
				),
			)
		);

		$this->ensureMelt( $order, 5000 );

		// Old quote archived before rotation, with its request snapshot.
		$archive = json_decode( (string) $order->get_meta( '_cashu_archived_melt_quotes' ), true );
		$this->assertCount( 1, $archive );
		$this->assertSame( 'q_old', $archive[0]['quote'] );
		$this->assertSame( 'lnbc_old_invoice', $archive[0]['request'] );

		// New quote persisted; headline total = amount + reserve + 1% buffer.
		$this->assertSame( 'q_new', $order->get_meta( '_cashu_melt_quote_id' ) );
		$this->assertSame( self::MINT, $order->get_meta( '_cashu_melt_mint' ) );
		$this->assertSame( 5000 + 10 + (int) ceil( 5010 * 0.01 ), $order->get_meta( '_cashu_melt_total' ) );
		$this->assertSame( self::BOLT11, $order->get_meta( '_cashu_melt_quote_request' ) );
	}

	public function test_melt_changed_mint_archives_without_consulting_old_mint(): void {
		// The issuing mint is no longer the trusted mint, so its state can't
		// be safely queried from here — archive for forensics and rotate at
		// the new mint. No state probe may be attempted.
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_quote_id'     => 'q_old',
				'_cashu_melt_quote_expiry' => (string) ( time() + 600 ),
				'_cashu_melt_mint'         => 'https://other-mint.example',
			)
		);
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode(
					array(
						'quote'       => 'q_new',
						'expiry'      => time() + 900,
						'amount'      => 5000,
						'fee_reserve' => 0,
						'unit'        => 'sat',
					)
				),
			)
		);

		$this->ensureMelt( $order, 5000 );

		$archive = json_decode( (string) $order->get_meta( '_cashu_archived_melt_quotes' ), true );
		$this->assertSame( 'q_old', $archive[0]['quote'] );
		$this->assertSame( 'https://other-mint.example', $archive[0]['mint'] );
		$this->assertSame( 'q_new', $order->get_meta( '_cashu_melt_quote_id' ) );
	}

	public function test_melt_invoice_amount_mismatch_aborts_settlement(): void {
		// The mint decodes the real BOLT11; if its amount differs from what
		// we asked the LNURL service for, the order would settle for the
		// wrong number of sats — hard abort.
		$this->stubGatewayBaseline();
		$order = $this->mockOrder( 42 );

		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode(
					array(
						'quote'       => 'q_new',
						'expiry'      => time() + 900,
						'amount'      => 4999, // != 5000 requested
						'fee_reserve' => 10,
						'unit'        => 'sat',
					)
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'amount mismatch' );

		$this->ensureMelt( $order, 5000 );
	}

	public function test_melt_rejects_malformed_quote_response(): void {
		$this->stubGatewayBaseline();
		$order = $this->mockOrder( 42 );

		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode(
					array(
						'quote'  => 'q_new',
						'expiry' => time() + 900,
						'amount' => 5000,
						'unit'   => 'usd', // wrong unit
					)
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid melt quote response' );

		$this->ensureMelt( $order, 5000 );
	}

	// ── mint leg: never abandon a quote the customer may have paid ──────

	public function test_mint_quote_reused_when_amount_matches_and_unexpired(): void {
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_mint_quote_id'     => 'mq_old',
				'_cashu_mint_quote_amount' => '5061',
				'_cashu_mint_quote_expiry' => (string) ( time() + 600 ),
				'_cashu_mint_quote_mint'   => self::MINT,
			)
		);
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		$this->ensureMint( $order, 5061 );

		$this->assertSame( 'mq_old', $order->get_meta( '_cashu_mint_quote_id' ) );
	}

	public function test_mint_quote_with_no_expiry_treated_as_still_valid(): void {
		// expiry == 0 means the mint doesn't advertise one (spec allows
		// null/missing) — must read as valid, not as expired-at-epoch.
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_mint_quote_id'     => 'mq_old',
				'_cashu_mint_quote_amount' => '5061',
				'_cashu_mint_quote_expiry' => '0',
				'_cashu_mint_quote_mint'   => self::MINT,
			)
		);
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		$this->ensureMint( $order, 5061 );

		$this->assertSame( 'mq_old', $order->get_meta( '_cashu_mint_quote_id' ) );
	}

	/** @dataProvider preservedMintStates */
	public function test_mint_quote_preserved_despite_amount_change_when( string $state ): void {
		// The amount no longer matches (price re-quote), but the mint says
		// the customer's funds are bound to the old quote — keep it.
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_mint_quote_id'     => 'mq_old',
				'_cashu_mint_quote_amount' => '4000',
				'_cashu_mint_quote_expiry' => (string) ( time() - 600 ),
				'_cashu_mint_quote_mint'   => self::MINT,
			)
		);
		$body = '' === $state
			? new \WP_Error( 'http_request_failed', 'timeout' )
			: array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'state' => $state ) ),
			);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $body );
		Functions\expect( 'wp_remote_post' )->never();

		$this->ensureMint( $order, 5061 );

		$this->assertSame( 'mq_old', $order->get_meta( '_cashu_mint_quote_id' ) );
		$this->assertSame( '', $order->get_meta( '_cashu_archived_mint_quotes' ) );
	}

	public static function preservedMintStates(): array {
		return array(
			'PAID — customer paid, proofs unclaimed' => array( 'PAID' ),
			'ISSUED — proofs already claimed'        => array( 'ISSUED' ),
			'unknown — mint unreachable'             => array( '' ),
		);
	}

	public function test_mint_unpaid_quote_archived_then_rotated(): void {
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_mint_quote_id'      => 'mq_old',
				'_cashu_mint_quote_request' => 'lnbc_old_request',
				'_cashu_mint_quote_amount'  => '4000',
				'_cashu_mint_quote_expiry'  => (string) ( time() - 600 ),
				'_cashu_mint_quote_mint'    => self::MINT,
			)
		);
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'state' => 'UNPAID' ) ),
			)
		);
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode(
					array(
						'quote'   => 'mq_new',
						'request' => 'lnbc_new_request',
						'expiry'  => time() + 900,
					)
				),
			)
		);

		$this->ensureMint( $order, 5061 );

		$archive = json_decode( (string) $order->get_meta( '_cashu_archived_mint_quotes' ), true );
		$this->assertCount( 1, $archive );
		$this->assertSame( 'mq_old', $archive[0]['quote'] );
		$this->assertSame( 'lnbc_old_request', $archive[0]['request'] );
		$this->assertSame( 4000, $archive[0]['amount'] );

		$this->assertSame( 'mq_new', $order->get_meta( '_cashu_mint_quote_id' ) );
		$this->assertSame( 'lnbc_new_request', $order->get_meta( '_cashu_mint_quote_request' ) );
		$this->assertSame( 5061, $order->get_meta( '_cashu_mint_quote_amount' ) );
		$this->assertSame( self::MINT, $order->get_meta( '_cashu_mint_quote_mint' ) );
	}

	public function test_mint_changed_mint_skips_state_consult_but_still_archives(): void {
		$this->stubGatewayBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_mint_quote_id'     => 'mq_old',
				'_cashu_mint_quote_amount' => '4000',
				'_cashu_mint_quote_expiry' => (string) ( time() - 600 ),
				'_cashu_mint_quote_mint'   => 'https://other-mint.example',
			)
		);
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode(
					array(
						'quote'   => 'mq_new',
						'request' => 'lnbc_new_request',
						'expiry'  => time() + 900,
					)
				),
			)
		);

		$this->ensureMint( $order, 5061 );

		$archive = json_decode( (string) $order->get_meta( '_cashu_archived_mint_quotes' ), true );
		$this->assertSame( 'mq_old', $archive[0]['quote'] );
		$this->assertSame( 'https://other-mint.example', $archive[0]['mint'] );
		$this->assertSame( 'mq_new', $order->get_meta( '_cashu_mint_quote_id' ) );
	}

	public function test_mint_rejects_malformed_quote_response(): void {
		$this->stubGatewayBaseline();
		$order = $this->mockOrder( 42 );

		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'quote' => 'mq_new' ) ), // no request
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid mint quote response' );

		$this->ensureMint( $order, 5061 );
	}
}
