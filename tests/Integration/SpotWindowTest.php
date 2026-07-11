<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\SpotWindow;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * Window math + the one-sided tolerance slide. Price is primed via the
 * coinbase spot transient (100k USD/BTC) so fiatToSats runs with no HTTP:
 * a $100.00 order is exactly 100_000 sats.
 */
final class SpotWindowTest extends IntegrationTestCase {

	private function stubBaseline(): void {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->alias(
			static fn ( string $key ) => str_starts_with( $key, 'cashu_btc_spot_cb_' ) ? 100000.0 : false
		);
	}

	/** Meta for a quote payable for another hour, created 100s ago. */
	private function payableMeta(): array {
		return array(
			'_cashu_mint_quote_id'      => 'mq1',
			'_cashu_mint_quote_expiry'  => (string) ( time() + HOUR_IN_SECONDS ),
			'_cashu_mint_quote_created' => (string) ( time() - 100 ),
		);
	}

	private function slidableOrder( int $standing_sats, array $extra = array() ) {
		$order = $this->mockOrder(
			42,
			array_merge(
				$this->payableMeta(),
				array(
					'_cashu_spot_total' => (string) $standing_sats,
					'_cashu_spot_time'  => (string) ( time() - 1000 ),
				),
				$extra
			)
		);
		$order->shouldReceive( 'get_total' )->andReturn( '100.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		return $order;
	}

	// ── payable_until ────────────────────────────────────────────────────

	public function test_payable_until_is_min_of_expiry_and_24h_cap(): void {
		$this->stubBaseline();
		$created = time() - 100;
		$order   = $this->mockOrder(
			42,
			array(
				'_cashu_mint_quote_id'      => 'mq1',
				'_cashu_mint_quote_expiry'  => (string) ( $created + 3 * DAY_IN_SECONDS ),
				'_cashu_mint_quote_created' => (string) $created,
			)
		);
		$this->assertSame( $created + DAY_IN_SECONDS, SpotWindow::payable_until( $order ) );
	}

	public function test_payable_until_uses_cap_when_mint_advertises_no_expiry(): void {
		$this->stubBaseline();
		$created = time() - 100;
		$order   = $this->mockOrder(
			42,
			array(
				'_cashu_mint_quote_id'      => 'mq1',
				'_cashu_mint_quote_created' => (string) $created,
			)
		);
		$this->assertSame( $created + DAY_IN_SECONDS, SpotWindow::payable_until( $order ) );
	}

	public function test_payable_until_legacy_order_trusts_expiry_alone(): void {
		$this->stubBaseline();
		$expiry = time() + 600;
		$order  = $this->mockOrder(
			42,
			array(
				'_cashu_mint_quote_id'     => 'mq1',
				'_cashu_mint_quote_expiry' => (string) $expiry,
			)
		);
		$this->assertSame( $expiry, SpotWindow::payable_until( $order ) );
	}

	public function test_payable_until_zero_without_a_quote(): void {
		$this->stubBaseline();
		$order = $this->mockOrder( 42 );
		$this->assertSame( 0, SpotWindow::payable_until( $order ) );
		$this->assertFalse( SpotWindow::quote_payable( $order ) );
	}

	// ── maybe_slide ──────────────────────────────────────────────────────

	public function test_slides_when_fresh_quote_matches_standing(): void {
		$this->stubBaseline();
		$order = $this->slidableOrder( 100000 );
		$this->assertTrue( SpotWindow::maybe_slide( $order ) );
		// Window refreshed, standing total untouched (the anchor).
		$this->assertGreaterThan( time() - 5, (int) $order->get_meta( '_cashu_spot_time' ) );
		$this->assertSame( '100000', (string) $order->get_meta( '_cashu_spot_total' ) );
	}

	public function test_slides_inside_the_one_percent_band(): void {
		$this->stubBaseline();
		// Standing 99_500: fresh 100_000 <= floor(99_500 * 1.01) = 100_495.
		$this->assertTrue( SpotWindow::maybe_slide( $this->slidableOrder( 99500 ) ) );
	}

	public function test_does_not_slide_beyond_the_band(): void {
		$this->stubBaseline();
		// Standing 99_000: fresh 100_000 > floor(99_000 * 1.01) = 99_990.
		$order = $this->slidableOrder( 99000 );
		$old   = (int) $order->get_meta( '_cashu_spot_time' );
		$this->assertFalse( SpotWindow::maybe_slide( $order ) );
		$this->assertSame( $old, (int) $order->get_meta( '_cashu_spot_time' ) );
	}

	public function test_slides_when_btc_rose_and_old_invoice_over_covers(): void {
		$this->stubBaseline();
		// One-sided: standing 120_000 >> fresh 100_000 still slides.
		$this->assertTrue( SpotWindow::maybe_slide( $this->slidableOrder( 120000 ) ) );
	}

	public function test_tolerance_filter_is_applied(): void {
		$this->stubBaseline();
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $tag, $value ) => SpotWindow::TOLERANCE_FILTER === $tag ? 0.05 : $value
		);
		// Standing 96_000 is beyond 1% but inside 5%.
		$this->assertTrue( SpotWindow::maybe_slide( $this->slidableOrder( 96000 ) ) );
	}

	public function test_no_slide_when_quote_not_payable(): void {
		$this->stubBaseline();
		$order = $this->slidableOrder(
			100000,
			array( '_cashu_mint_quote_expiry' => (string) ( time() - 60 ) )
		);
		$this->assertFalse( SpotWindow::maybe_slide( $order ) );
	}

	public function test_no_slide_without_standing_total(): void {
		$this->stubBaseline();
		$order = $this->mockOrder( 42, $this->payableMeta() );
		$this->assertFalse( SpotWindow::maybe_slide( $order ) );
	}
}
