<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Helpers\CashuPaths;
use Cashu\WC\Tests\IntegrationTestCase;
use Mockery;

/**
 * Smoke test for the tab-rendering block in CashuGateway::receipt_page.
 *
 * Stubs the full WP/WC environment that receipt_page touches: order
 * lookups, REST URLs, meta values, wp_enqueue_* calls. Captures the
 * echoed HTML and asserts on the tab structure for two scenarios:
 *   1. All three paths enabled → three buttons + tab-strip wrapper.
 *   2. Only `cashu` enabled    → no tab-strip wrapper, data-default-tab=cashu.
 */
final class ReceiptPageTabsTest extends IntegrationTestCase {

	private function stubEnvironment( array $paths, string $default ): void {
		$options = array(
			'cashu_lightning_address' => 'me@example.com',
			'cashu_trusted_mint'      => 'https://mint.example/Bitcoin',
			'cashu_paths'             => $paths,
			'cashu_default_path'      => $default,
		);

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = '' ) use ( $options ) {
				return $options[ $key ] ?? $fallback;
			}
		);

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_x' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->alias(
			static function ( string $text ): void { echo $text; }
		);
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'rest_url' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias(
			static fn ( $url ) => parse_url( (string) $url )
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'wc_get_order' )->alias( fn () => $this->buildOrder() );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
	}

	private function buildOrder() {
		// Set up timestamps so receipt_page skips setup_cashu_payment:
		// spot_expiry = spot_time + 900 must be > time()  →  spot_time = now - 60
		// quote_expiry must be >= spot_expiry             →  quote_expiry = now + 3600
		// mint_quote_id must be non-empty                 →  set below
		$now       = time();
		$spotTime  = $now - 60;
		$spotExpiry = $spotTime + CashuGateway::QUOTE_EXPIRY_SECS; // now + 840
		$quoteExpiry = $now + 3600; // > spot_expiry

		$payloads = array(
			'_cashu_spot_total'         => 1000,
			'_cashu_spot_time'          => $spotTime,
			'_cashu_melt_quote_id'      => 'melt-quote-123',
			'_cashu_melt_quote_expiry'  => $quoteExpiry,
			'_cashu_melt_mint'          => 'https://mint.example/Bitcoin',
			'_cashu_melt_total'         => 1050,
			'_cashu_mint_quote_id'      => 'mint-quote-123',
			'_cashu_mint_quote_request' => 'lnbc...',
			'_cashu_mint_quote_amount'  => 1000,
			'_cashu_mint_quote_expiry'  => $quoteExpiry,
			'_cashu_mint_quote_mint'    => 'https://mint.example/Bitcoin',
		);

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( 'pending' );
		$order->shouldReceive( 'get_total' )->andReturn( '10.00' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		$order->shouldReceive( 'get_order_key' )->andReturn( 'wc_test_key' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'cashu_default' );
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		$order->shouldReceive( 'read_meta_data' )->andReturn( null );
		$order->shouldReceive( 'save' )->andReturn( 123 );
		$order->shouldReceive( 'update_meta_data' )->andReturn( null );
		$order->shouldReceive( 'delete_meta_data' )->andReturn( null );
		$order->shouldReceive( 'update_status' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );
		$order->shouldReceive( 'get_checkout_payment_url' )->andReturn( '/checkout/order-pay/123' );
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( '/order-received/123' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( string $key ) use ( $payloads ) {
				return $payloads[ $key ] ?? '';
			}
		);
		return $order;
	}

	public function test_renders_all_three_tabs_when_all_paths_enabled(): void {
		$this->stubEnvironment( CashuPaths::DEFAULT_PATHS, 'unified' );

		$gw          = new CashuGateway();
		$gw->enabled = 'yes';

		ob_start();
		$gw->receipt_page( 123 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="cashu-tabs"', $output );
		$this->assertStringContainsString( 'data-cashu-tab="unified"', $output );
		$this->assertStringContainsString( 'data-cashu-tab="cashu"', $output );
		$this->assertStringContainsString( 'data-cashu-tab="lightning"', $output );
		$this->assertStringContainsString( 'data-default-tab="unified"', $output );
	}

	public function test_second_receipt_render_in_same_request_is_suppressed(): void {
		// A misconfigured checkout page (legacy shortcode left in alongside
		// the checkout block) runs the order-pay template twice in one
		// request, firing woocommerce_receipt_* twice. The second render
		// must be a no-op or the page carries two #cashu-pay-root ids and
		// a duplicate payment box.
		$paths = array(
			'unified'   => true,
			'cashu'     => true,
			'lightning' => true,
		);
		$this->stubEnvironment( $paths, 'unified' );

		$gw          = new CashuGateway();
		$gw->enabled = 'yes';

		ob_start();
		$gw->receipt_page( 123 );
		$first = (string) ob_get_clean();

		ob_start();
		$gw->receipt_page( 123 );
		$second = (string) ob_get_clean();

		$this->assertStringContainsString( 'cashu-pay-root', $first );
		$this->assertSame( '', $second );
	}

	public function test_omits_tab_strip_when_only_one_path_enabled(): void {
		$paths = array( 'unified' => false, 'cashu' => true, 'lightning' => false );
		$this->stubEnvironment( $paths, 'cashu' );

		$gw          = new CashuGateway();
		$gw->enabled = 'yes';

		ob_start();
		$gw->receipt_page( 123 );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'class="cashu-tabs"', $output );
		$this->assertStringContainsString( 'data-default-tab="cashu"', $output );
		$this->assertStringNotContainsString( 'data-cashu-tab=', $output );
	}
}
