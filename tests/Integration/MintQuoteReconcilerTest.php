<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\MintQuoteReconciler;
use Cashu\WC\Helpers\SettlementGuard;
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

	public function test_sweep_runs_live_cohort_then_backlog_cohort_query(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$calls = array();
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$calls ): array {
				$calls[] = $args;
				return array();
			}
		);

		MintQuoteReconciler::sweep();

		$this->assertCount( 2, $calls );
		$live    = $calls[0];
		$backlog = $calls[1];

		$this->assertSame( 20, $live['limit'] );
		$this->assertSame( 'cashu_default', $live['payment_method'] );
		$this->assertContains( 'cancelled', $live['status'] );
		$keys = array_column( $live['meta_query'], 'key' );
		$this->assertContains( '_cashu_mint_quote_id', $keys );
		$this->assertContains( MintQuoteReconciler::DONE_META, $keys );
		$this->assertStringStartsWith( '>', (string) $live['date_created'] );

		$this->assertSame( 20, $backlog['limit'] );
		$this->assertSame( $live['payment_method'], $backlog['payment_method'] );
		$this->assertSame( $live['meta_query'], $backlog['meta_query'] );
		$this->assertStringStartsWith( '<', (string) $backlog['date_created'] );
	}

	public function test_sweep_skips_backlog_query_when_live_cohort_fills_cap(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$calls = array();
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$calls ): array {
				$calls[] = $args;
				// Live cohort fills the cap; entries are not WC_Order so
				// sweep_one() skips them harmlessly via its instanceof guard.
				return '>' === substr( (string) $args['date_created'], 0, 1 )
					? array_fill( 0, 20, new \stdClass() )
					: array();
			}
		);

		MintQuoteReconciler::sweep();

		$this->assertCount( 1, $calls );
	}

	public function test_sweep_backlog_query_uses_remaining_slots(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$calls = array();
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$calls ): array {
				$calls[] = $args;
				return '>' === substr( (string) $args['date_created'], 0, 1 )
					? array_fill( 0, 5, new \stdClass() )
					: array();
			}
		);

		MintQuoteReconciler::sweep();

		$this->assertCount( 2, $calls );
		$this->assertSame( 15, $calls[1]['limit'] );
	}

	public function test_paid_current_quote_marks_notes_emails_and_closes_the_watch(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$order = $this->mockOrder( 42, $this->watchedMeta() );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'get_checkout_payment_url' )->andReturn( 'https://s.example/pay' );
		// Guard metas persist before either note (record_detection), then a
		// second save closes the watch once detection + the archived pass
		// are both done for this tick (nothing left to watch for).
		$order->shouldReceive( 'save' )->once()->ordered()->andReturn( 42 );
		// One admin note and one customer note (the email), never again.
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ) )->once()->ordered()->andReturn( 1 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ), 1 )->once()->ordered()->andReturn( 2 );
		$order->shouldReceive( 'save' )->once()->ordered()->andReturn( 42 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( $this->batchResponse( array( 'mq1' => 'PAID' ) ) );

		MintQuoteReconciler::sweep_one( $order );
		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DETECTED_META ) );
		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );

		// Second pass: the watch is already closed, so it short-circuits
		// before ever probing the mint again.
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

	public function test_aged_out_order_gets_a_final_probe_before_closing(): void {
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
		// Every order gets at least one probe before the watch closes, even
		// past its age-out point: a mint settlement landing right at the
		// edge of the grace window must never be missed silently.
		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( $this->batchResponse( array( 'mq1' => 'UNPAID' ) ) );

		MintQuoteReconciler::sweep_one( $order );

		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );
	}

	public function test_aged_out_tick_with_archived_hit_notes_recovery_not_watch_closed(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta = $this->watchedMeta();
		// Payable window ended two days ago: past window + 24h grace, so this
		// tick both runs the archived-quote pass and reaches the aged-out
		// close check.
		$meta['_cashu_mint_quote_expiry']  = (string) ( time() - 2 * DAY_IN_SECONDS );
		$meta['_cashu_mint_quote_created'] = (string) ( time() - 3 * DAY_IN_SECONDS );
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
		// Only the archived-quote recovery note, never the contradictory
		// "no customer payment" watch-closed note in the same tick.
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ) )->once()->andReturn( 1 );
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
		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );
	}

	public function test_paid_once_then_cancelled_order_is_marked_done_without_probing_or_notifying(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta = $this->watchedMeta();
		$meta[ SettlementGuard::PAID_ONCE_META ] = (string) ( time() - HOUR_IN_SECONDS );
		$order                                   = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'add_order_note' )->never();
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
