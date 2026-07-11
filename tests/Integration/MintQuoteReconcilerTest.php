<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\MintQuoteReconciler;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * Detection-only sweep: marks, notes, and emails; never mints, never
 * completes. Follows MeltReconcilerSweepTest's structure.
 */
final class MintQuoteReconcilerTest extends IntegrationTestCase {

	private const MINT = 'https://mint.example';

	private function stubBaseline(): void {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( static fn ( $t ): bool => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( static fn ( $r ): int => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( static fn ( $r ): string => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );
	}

	/** NUT-29 batch response for the given quote => state map. */
	private function batchResponse( array $states ): array {
		$list = array();
		foreach ( $states as $quote => $state ) {
			$list[] = array(
				'quote' => $quote,
				'state' => $state,
			);
		}
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( $list ),
		);
	}

	private function watchedMeta(): array {
		return array(
			'_cashu_mint_quote_id'      => 'mq1',
			'_cashu_mint_quote_mint'    => self::MINT,
			'_cashu_mint_quote_expiry'  => (string) ( time() + HOUR_IN_SECONDS ),
			'_cashu_mint_quote_created' => (string) ( time() - 100 ),
		);
	}

	public function test_sweep_query_is_sparse_and_bounded(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$captured = array();
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$captured ): array {
				$captured = $args;
				return array();
			}
		);

		MintQuoteReconciler::sweep();

		$this->assertSame( 20, $captured['limit'] );
		$this->assertSame( 'cashu_default', $captured['payment_method'] );
		$this->assertContains( 'cancelled', $captured['status'] );
		$keys = array_column( $captured['meta_query'], 'key' );
		$this->assertContains( '_cashu_mint_quote_id', $keys );
		$this->assertContains( MintQuoteReconciler::DONE_META, $keys );
	}

	public function test_paid_current_quote_marks_notes_and_emails_once(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$order = $this->mockOrder( 42, $this->watchedMeta() );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'get_checkout_payment_url' )->andReturn( 'https://s.example/pay' );
		// Guard metas must persist before either note: a crash between an
		// emailed note and save() must never re-send it on the next sweep.
		$order->shouldReceive( 'save' )->once()->ordered()->andReturn( 42 );
		// One admin note and one customer note (the email), never again.
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ) )->once()->ordered()->andReturn( 1 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ), 1 )->once()->ordered()->andReturn( 2 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->twice()
			->andReturn( $this->batchResponse( array( 'mq1' => 'PAID' ) ) );

		MintQuoteReconciler::sweep_one( $order );
		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DETECTED_META ) );

		// Second pass: probe repeats, notes and email do not.
		MintQuoteReconciler::sweep_one( $order );
	}

	public function test_paid_archived_quote_gets_a_note_but_no_detection_marker(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta = $this->watchedMeta();
		$meta['_cashu_archived_mint_quotes'] = (string) json_encode(
			array(
				array(
					'quote'  => 'mq_old',
					'amount' => 5000,
				),
			)
		);
		$order = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			$this->batchResponse(
				array(
					'mq1'    => 'UNPAID',
					'mq_old' => 'PAID',
				)
			)
		);

		MintQuoteReconciler::sweep_one( $order );

		$this->assertSame( '', (string) $order->get_meta( MintQuoteReconciler::DETECTED_META ) );
		$this->assertNotSame( '', (string) $order->get_meta( '_cashu_archived_paid_noted' ) );
	}

	public function test_aged_out_order_is_marked_done_without_probing(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta = $this->watchedMeta();
		// Payable window ended two days ago: past window + 24h grace.
		$meta['_cashu_mint_quote_expiry']  = (string) ( time() - 2 * DAY_IN_SECONDS );
		$meta['_cashu_mint_quote_created'] = (string) ( time() - 3 * DAY_IN_SECONDS );
		$order = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'wp_remote_get' )->never();

		MintQuoteReconciler::sweep_one( $order );

		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );
	}

	public function test_paid_order_is_marked_done_without_probing(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$order = $this->mockOrder( 42, $this->watchedMeta() );
		$order->shouldReceive( 'is_paid' )->andReturn( true );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->never();

		MintQuoteReconciler::sweep_one( $order );

		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );
	}

	public function test_lock_contention_skips_quietly(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb( 0 ); // lock held elsewhere
		$order = $this->mockOrder( 42, $this->watchedMeta() );
		Functions\expect( 'wp_remote_post' )->never();

		MintQuoteReconciler::sweep_one( $order );

		$this->assertTrue( true );
	}
}
