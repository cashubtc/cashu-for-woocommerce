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

	/** Mirrors MintQuoteReconciler::OFFSET_OPTION (private, so duplicated here). */
	private const OFFSET_OPTION = 'cashu_wc_mint_sweep_offset';

	private function stubBaseline(): void {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'update_option' )->justReturn( true );
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

	/** Stub get_option/update_option onto a shared backing array, keyed by option name. */
	private function stubOptions( array &$options ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$options ): bool {
				$options[ $key ] = $value;
				return true;
			}
		);
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

	public function test_sweep_still_runs_backlog_query_with_one_slot_when_live_cohort_fills_cap(): void {
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

		// A saturated live cohort must never starve the backlog entirely: a
		// full page still yields one guaranteed backlog slot.
		$this->assertCount( 2, $calls );
		$this->assertSame( 1, $calls[1]['limit'] );
	}

	public function test_sweep_gives_backlog_one_slot_even_when_a_full_live_page_is_real_orders(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();

		// One cheap already-paid order, reused 20 times for the live cohort:
		// sweep_one() takes the DONE fast path (is_paid() short-circuit)
		// without probing the mint. A pre-deployment backlog order sits
		// behind it, past the watch horizon cutoff.
		$live_order = $this->mockOrder( 42, $this->watchedMeta() );
		$live_order->shouldReceive( 'is_paid' )->andReturn( true );

		$backlog_meta                          = $this->watchedMeta();
		$backlog_meta['_cashu_mint_quote_id']  = 'mq_backlog';
		$backlog_order                         = $this->mockOrder( 99, $backlog_meta );
		$backlog_order->shouldReceive( 'is_paid' )->andReturn( true );

		Functions\when( 'wc_get_order' )->alias(
			static function ( int $id ) use ( $live_order, $backlog_order ) {
				return 99 === $id ? $backlog_order : $live_order;
			}
		);

		$calls = array();
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$calls, $live_order, $backlog_order ): array {
				$calls[] = $args;
				return '>' === substr( (string) $args['date_created'], 0, 1 )
					? array_fill( 0, 20, $live_order )
					: array( $backlog_order );
			}
		);

		MintQuoteReconciler::sweep();

		$this->assertCount( 2, $calls );
		$live    = $calls[0];
		$backlog = $calls[1];
		$this->assertSame( 20, $live['limit'] );
		$this->assertStringStartsWith( '>', (string) $live['date_created'] );
		$this->assertSame( 1, $backlog['limit'] );
		$this->assertStringStartsWith( '<', (string) $backlog['date_created'] );

		// The backlog order was actually probed and closed out, not skipped.
		$this->assertNotSame( '', (string) $backlog_order->get_meta( MintQuoteReconciler::DONE_META ) );
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

	public function test_sweep_rotates_the_offset_after_a_full_live_page(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$options = array();
		$this->stubOptions( $options );

		// One cheap already-paid order, reused 20 times: sweep_one() takes
		// the DONE fast path (is_paid() short-circuit) without probing the
		// mint, so a full-page tick stays cheap here too.
		$order = $this->mockOrder( 42, $this->watchedMeta() );
		$order->shouldReceive( 'is_paid' )->andReturn( true );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$calls = array();
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$calls, $order ): array {
				$calls[] = $args;
				return '>' === substr( (string) $args['date_created'], 0, 1 )
					? array_fill( 0, 20, $order )
					: array();
			}
		);

		MintQuoteReconciler::sweep();
		$this->assertSame( 0, $calls[0]['offset'] );
		$this->assertSame( 20, $options[ self::OFFSET_OPTION ] );

		// Next tick's live-cohort query starts from the stored offset. A full
		// live page still runs a one-slot backlog query (calls[1]) before the
		// second tick's live query (calls[2]).
		MintQuoteReconciler::sweep();
		$this->assertSame( 20, $calls[2]['offset'] );
	}

	public function test_sweep_resets_the_offset_after_a_short_live_page(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$options = array( self::OFFSET_OPTION => 20 );
		$this->stubOptions( $options );

		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ): array {
				// Short page (not a full 20): entries are not WC_Order so
				// sweep_one() skips them harmlessly via its instanceof guard.
				return '>' === substr( (string) $args['date_created'], 0, 1 )
					? array_fill( 0, 5, new \stdClass() )
					: array();
			}
		);

		MintQuoteReconciler::sweep();

		$this->assertSame( 0, $options[ self::OFFSET_OPTION ] );
	}

	public function test_sweep_retries_from_zero_when_the_cohort_shrank_below_the_offset(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$options = array( self::OFFSET_OPTION => 20 );
		$this->stubOptions( $options );

		$live_offsets = array();
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$live_offsets ): array {
				if ( '>' === substr( (string) $args['date_created'], 0, 1 ) ) {
					$live_offsets[] = $args['offset'];
				}
				return array();
			}
		);

		MintQuoteReconciler::sweep();

		// The first page at offset 20 comes back empty (the cohort shrank),
		// so sweep() retries once from offset 0 in the same tick rather than
		// wasting it.
		$this->assertSame( array( 20, 0 ), $live_offsets );
		$this->assertSame( 0, $options[ self::OFFSET_OPTION ] );
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

	public function test_numeric_quote_id_state_is_not_lost_by_key_renumbering(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta                          = $this->watchedMeta();
		$meta['_cashu_mint_quote_id']  = '12345';
		$order = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'get_checkout_payment_url' )->andReturn( 'https://s.example/pay' );
		$order->shouldReceive( 'save' )->twice()->andReturn( 42 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ) )->once()->andReturn( 1 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ), 1 )->once()->andReturn( 2 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		// A purely numeric quote id: PHP casts it to an integer array key, so
		// array_merge() (unlike array_replace()) renumbers it away from 0 and
		// the PAID state is lost at the $states[$current] lookup.
		Functions\expect( 'wp_remote_post' )->once()
			->andReturn( $this->batchResponse( array( '12345' => 'PAID' ) ) );

		MintQuoteReconciler::sweep_one( $order );

		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DETECTED_META ) );
	}

	public function test_detection_keeps_watching_while_an_archived_invoice_is_payable(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta                                 = $this->watchedMeta();
		$meta['_cashu_archived_mint_quotes']  = (string) json_encode(
			array(
				array(
					'quote'  => 'mq_old',
					'amount' => 5000,
					'expiry' => time() + DAY_IN_SECONDS,
				),
			)
		);
		$order = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'get_checkout_payment_url' )->andReturn( 'https://s.example/pay' );
		// Only the first tick's detection saves and notes; the archived
		// invoice is still payable, so the watch stays open and the second
		// tick's record_detection() call is a guarded no-op.
		$order->shouldReceive( 'save' )->once()->andReturn( 42 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ) )->once()->andReturn( 1 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ), 1 )->once()->andReturn( 2 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->twice()->andReturn(
			$this->batchResponse(
				array(
					'mq1'    => 'PAID',
					'mq_old' => 'UNPAID',
				)
			)
		);

		MintQuoteReconciler::sweep_one( $order );
		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DETECTED_META ) );
		$this->assertSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );

		// Second tick: still watching the archived invoice, so it probes the
		// mint again but adds no further notes.
		MintQuoteReconciler::sweep_one( $order );
		$this->assertSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );
	}

	public function test_detection_marks_done_when_archived_invoices_are_dead(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta                                 = $this->watchedMeta();
		// Archived expiry is past its payable window plus the 24h grace.
		$meta['_cashu_archived_mint_quotes']  = (string) json_encode(
			array(
				array(
					'quote'  => 'mq_old',
					'amount' => 5000,
					'expiry' => time() - ( 2 * DAY_IN_SECONDS + 60 ),
				),
			)
		);
		$order = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'get_checkout_payment_url' )->andReturn( 'https://s.example/pay' );
		$order->shouldReceive( 'save' )->twice()->andReturn( 42 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ) )->once()->andReturn( 1 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ), 1 )->once()->andReturn( 2 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			$this->batchResponse(
				array(
					'mq1'    => 'PAID',
					'mq_old' => 'UNPAID',
				)
			)
		);

		MintQuoteReconciler::sweep_one( $order );

		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DETECTED_META ) );
		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );
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

	public function test_archived_quote_from_a_different_mint_is_probed_at_its_own_mint(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta                                 = $this->watchedMeta();
		$meta['_cashu_mint_quote_mint']       = 'https://new.example';
		$meta['_cashu_archived_mint_quotes']  = (string) json_encode(
			array(
				array(
					'quote'  => 'mq_old',
					'mint'   => 'https://old.example',
					'amount' => 5000,
					'expiry' => time() + 2 * DAY_IN_SECONDS,
				),
			)
		);
		$order = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		// Two mints, two batch calls: the current quote's mint (new.example)
		// reports UNPAID, the archived quote's own mint (old.example, where
		// it was actually issued) reports PAID.
		$urls = array();
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url ) use ( &$urls ): array {
				$urls[] = $url;
				return false !== strpos( $url, 'new.example' )
					? $this->batchResponse( array( 'mq1' => 'UNPAID' ) )
					: $this->batchResponse( array( 'mq_old' => 'PAID' ) );
			}
		);

		MintQuoteReconciler::sweep_one( $order );

		$this->assertCount( 2, $urls );
		$this->assertContains( 'https://new.example/v1/mint/quote/bolt11/check', $urls );
		$this->assertContains( 'https://old.example/v1/mint/quote/bolt11/check', $urls );
		$this->assertSame( '', (string) $order->get_meta( MintQuoteReconciler::DETECTED_META ) );
		$this->assertNotSame( '', (string) $order->get_meta( '_cashu_archived_paid_noted' ) );
	}

	public function test_age_out_honours_the_latest_archived_expiry(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta = $this->watchedMeta();
		// The current quote alone would have aged out (past window + grace),
		// but an archived quote at a different mint is still payable for
		// another day: the watch must stay open for it.
		$meta['_cashu_mint_quote_expiry']    = (string) ( time() - 3 * DAY_IN_SECONDS );
		$meta['_cashu_mint_quote_created']   = (string) ( time() - 4 * DAY_IN_SECONDS );
		$meta['_cashu_archived_mint_quotes'] = (string) json_encode(
			array(
				array(
					'quote'  => 'mq_old',
					'mint'   => 'https://old.example',
					'amount' => 5000,
					'expiry' => time() + DAY_IN_SECONDS,
				),
			)
		);
		$order = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'add_order_note' )->never();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url ): array {
				return false !== strpos( $url, 'old.example' )
					? $this->batchResponse( array( 'mq_old' => 'UNPAID' ) )
					: $this->batchResponse( array( 'mq1' => 'UNPAID' ) );
			}
		);

		MintQuoteReconciler::sweep_one( $order );

		$this->assertSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );
	}

	public function test_zero_expiry_current_quote_is_not_aged_out_by_a_stale_archived_expiry(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta = $this->watchedMeta();
		// No advertised expiry on the current quote (spec-legal, treated as
		// still-valid elsewhere): its own deadline must fall through to
		// SpotWindow::payable_until(), which is created (recent) + 24h, still
		// in the future.
		$meta['_cashu_mint_quote_expiry']    = '0';
		$meta['_cashu_mint_quote_created']   = (string) ( time() - 100 );
		// A stale archived expiry, long past its window plus grace: it must
		// only ever extend the watch, never shorten the current quote's own
		// deadline down to this.
		$meta['_cashu_archived_mint_quotes'] = (string) json_encode(
			array(
				array(
					'quote'  => 'mq_old',
					'amount' => 5000,
					'expiry' => time() - 3 * DAY_IN_SECONDS,
				),
			)
		);
		$order = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'add_order_note' )->never();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			$this->batchResponse(
				array(
					'mq1'    => 'UNPAID',
					'mq_old' => 'UNPAID',
				)
			)
		);

		MintQuoteReconciler::sweep_one( $order );

		$this->assertSame( '', (string) $order->get_meta( MintQuoteReconciler::DETECTED_META ) );
		$this->assertSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );
	}

	public function test_archived_entry_with_zero_expiry_and_recent_created_stays_watched(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta                                 = $this->watchedMeta();
		// A 0 expiry is spec-legal, not "already dead": with a recent
		// created stamp of its own, the entry's effective expiry is
		// created + the 24h max window, still far in the future.
		$meta['_cashu_archived_mint_quotes']  = (string) json_encode(
			array(
				array(
					'quote'   => 'mq_old',
					'amount'  => 5000,
					'expiry'  => 0,
					'created' => time() - 100,
				),
			)
		);
		$order = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'get_checkout_payment_url' )->andReturn( 'https://s.example/pay' );
		$order->shouldReceive( 'save' )->once()->andReturn( 42 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ) )->once()->andReturn( 1 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ), 1 )->once()->andReturn( 2 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			$this->batchResponse(
				array(
					'mq1'    => 'PAID',
					'mq_old' => 'UNPAID',
				)
			)
		);

		MintQuoteReconciler::sweep_one( $order );

		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DETECTED_META ) );
		$this->assertSame( '', (string) $order->get_meta( MintQuoteReconciler::DONE_META ) );
	}

	public function test_archived_entry_with_zero_expiry_and_no_created_falls_back_to_order_created(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$meta                                 = $this->watchedMeta();
		// Legacy archive entry, predating the 'created' field: falls back to
		// the ORDER's current-quote created stamp, well past the 24h cap.
		$meta['_cashu_mint_quote_created']    = (string) ( time() - 3 * DAY_IN_SECONDS - HOUR_IN_SECONDS );
		$meta['_cashu_archived_mint_quotes']  = (string) json_encode(
			array(
				array(
					'quote'  => 'mq_old',
					'amount' => 5000,
					'expiry' => 0,
				),
			)
		);
		$order = $this->mockOrder( 42, $meta );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'get_checkout_payment_url' )->andReturn( 'https://s.example/pay' );
		$order->shouldReceive( 'save' )->twice()->andReturn( 42 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ) )->once()->andReturn( 1 );
		$order->shouldReceive( 'add_order_note' )
			->with( \Mockery::type( 'string' ), 1 )->once()->andReturn( 2 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			$this->batchResponse(
				array(
					'mq1'    => 'PAID',
					'mq_old' => 'UNPAID',
				)
			)
		);

		MintQuoteReconciler::sweep_one( $order );

		$this->assertNotSame( '', (string) $order->get_meta( MintQuoteReconciler::DETECTED_META ) );
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
