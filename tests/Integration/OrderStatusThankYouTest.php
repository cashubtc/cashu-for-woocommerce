<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\CashuWCPlugin;
use Cashu\WC\Tests\IntegrationTestCase;

final class OrderStatusThankYouTest extends IntegrationTestCase {

	private function renderFor( string $status ): string {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		ob_start();
		CashuWCPlugin::orderStatusThankYouPage( 42 );
		return (string) ob_get_clean();
	}

	public function test_pending_order_sets_late_settlement_expectation(): void {
		$html = $this->renderFor( 'pending' );
		$this->assertStringContainsString( 'Waiting payment', $html );
		$this->assertStringContainsString( 'detect it automatically', $html );
	}

	public function test_paid_order_shows_no_expectation_line(): void {
		$html = $this->renderFor( 'processing' );
		$this->assertStringNotContainsString( 'detect it automatically', $html );
	}
}
