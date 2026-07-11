<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\CashuWCPlugin;
use Cashu\WC\Helpers\MintQuoteReconciler;
use Cashu\WC\Tests\IntegrationTestCase;

final class LateSettlementHooksTest extends IntegrationTestCase {

	private function stubBaseline(): void {
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
	}

	private function payableMeta(): array {
		return array(
			'_cashu_mint_quote_id'      => 'mq1',
			'_cashu_mint_quote_expiry'  => (string) ( time() + HOUR_IN_SECONDS ),
			'_cashu_mint_quote_created' => (string) ( time() - 100 ),
		);
	}

	// ── cancel veto ──────────────────────────────────────────────────────

	public function test_veto_while_quote_payable(): void {
		$this->stubBaseline();
		$order = $this->mockOrder( 42, $this->payableMeta() );
		$this->assertFalse( CashuWCPlugin::preventCancelDuringSettlement( true, $order ) );
	}

	public function test_veto_when_detection_marker_present(): void {
		$this->stubBaseline();
		$order = $this->mockOrder(
			42,
			array( MintQuoteReconciler::DETECTED_META => (string) time() )
		);
		$this->assertFalse( CashuWCPlugin::preventCancelDuringSettlement( true, $order ) );
	}

	public function test_cancel_proceeds_once_quote_expired_and_nothing_detected(): void {
		$this->stubBaseline();
		$order = $this->mockOrder(
			42,
			array(
				'_cashu_mint_quote_id'      => 'mq1',
				'_cashu_mint_quote_expiry'  => (string) ( time() - 60 ),
				'_cashu_mint_quote_created' => (string) ( time() - 1000 ),
			)
		);
		$this->assertTrue( CashuWCPlugin::preventCancelDuringSettlement( true, $order ) );
	}

	public function test_veto_ignores_other_gateways(): void {
		$this->stubBaseline();
		$order = $this->mockOrder( 42, $this->payableMeta() );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'stripe' );
		$this->assertTrue( CashuWCPlugin::preventCancelDuringSettlement( true, $order ) );
	}

	// ── pay-page gate ────────────────────────────────────────────────────

	public function test_gate_opens_cancelled_only_after_detection(): void {
		$this->stubBaseline();
		$statuses = array( 'pending', 'failed' );

		$plain = $this->mockOrder( 42 );
		$this->assertSame(
			$statuses,
			CashuWCPlugin::allowLateSettlementPayment( $statuses, $plain )
		);

		$detected = $this->mockOrder(
			43,
			array( MintQuoteReconciler::DETECTED_META => (string) time() )
		);
		$this->assertContains(
			'cancelled',
			CashuWCPlugin::allowLateSettlementPayment( $statuses, $detected )
		);
	}

	public function test_gate_ignores_other_gateways_and_no_duplicates(): void {
		$this->stubBaseline();
		$other = $this->mockOrder( 44, array( MintQuoteReconciler::DETECTED_META => '1' ) );
		$other->shouldReceive( 'get_payment_method' )->andReturn( 'stripe' );
		$this->assertSame(
			array( 'pending' ),
			CashuWCPlugin::allowLateSettlementPayment( array( 'pending' ), $other )
		);

		$detected = $this->mockOrder( 45, array( MintQuoteReconciler::DETECTED_META => '1' ) );
		$result   = CashuWCPlugin::allowLateSettlementPayment( array( 'cancelled' ), $detected );
		$this->assertSame( array( 'cancelled' ), $result );
	}
}
