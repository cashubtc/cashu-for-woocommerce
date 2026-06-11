<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\ConfirmMeltQuoteController;
use Cashu\WC\Tests\IntegrationTestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Covers ConfirmMeltQuoteController::confirm_melt_quote, focusing on the
 * resolve_pending_melt branch — the cashu-leg poll that lets a mint settle
 * an in-flight LN payment after PayController stashed the pending marker.
 *
 * A stuck pending marker would otherwise pay a cached mint probe per TTL
 * window indefinitely. The 24h PENDING_MARKER_MAX_AGE
 * TTL drops the marker and falls through to UNPAID/EXPIRED.
 */
final class ResolvePendingMeltTest extends IntegrationTestCase {

	private function stubControllerBaseline(): void {
		Functions\when( 'is_wp_error' )->alias(
			static fn ( $thing ): bool => $thing instanceof WP_Error
		);
		Functions\when( 'rest_ensure_response' )->alias(
			static fn ( $data ) => new WP_REST_Response( $data )
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( static fn ( $r ): int => $r['response']['code'] ?? 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( static fn ( $r ): string => $r['body'] ?? '' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( '__' )->returnArg( 1 );

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	private function request( int $order_id, string $order_key ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_params(
			array(
				'order_id'  => $order_id,
				'order_key' => $order_key,
			)
		);
		return $req;
	}

	public function test_aged_out_pending_marker_is_cleared_and_falls_through(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$now              = time();
		$aged_pending_at  = $now - DAY_IN_SECONDS - 60; // 1 minute past TTL
		$spot_time        = $now - 100;                  // spot still valid

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q_stuck',
				'_cashu_melt_pending_at'       => (string) $aged_pending_at,
				'_cashu_melt_mint'             => 'https://m.example',
				'_cashu_spot_time'             => (string) $spot_time,
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );
		// Age-out writes an orphan order note matching MeltReconciler's, so
		// the admin sees the same recovery hint whichever path wins.
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		// The mint MUST NOT be hit when the marker is past TTL.
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		// After TTL clears the marker, the call falls through to the normal
		// UNPAID/EXPIRED branch. Spot is still valid so we expect UNPAID.
		$this->assertSame( 'UNPAID', $response->get_data()['state'] );
		// mockOrder's delete_meta_data unsets the backing array, so after the
		// run the markers are gone — confirms the TTL path actually ran.
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_at' ) );
	}

	public function test_fresh_pending_marker_hits_mint_state(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q_fresh',
				'_cashu_melt_pending_at'       => (string) ( time() - 30 ), // well within TTL
				'_cashu_melt_mint'             => 'https://m.example',
				'_cashu_spot_time'             => (string) time(),
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		// MintClient::melt_quote_state talks to the mint via
		// wp_remote_get. Returning PENDING keeps the order in pending state
		// without triggering the mark_paid flow.
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array( 'state' => 'PENDING' ) ),
				)
			);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'PENDING', $response->get_data()['state'] );
		// Marker preserved for the next poll.
		$this->assertSame( 'q_fresh', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_no_pending_marker_skips_mint_entirely(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				// no _cashu_melt_pending_quote_id => regular steady-state poll
				'_cashu_spot_time' => (string) time(),
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		$this->assertSame( 'UNPAID', $response->get_data()['state'] );
	}

	public function test_unknown_mint_state_keeps_marker_for_cron(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q_unreachable',
				'_cashu_melt_pending_at'       => (string) ( time() - 30 ),
				'_cashu_melt_mint'             => 'https://m.example',
				'_cashu_spot_time'             => (string) time(),
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		// Mint is unreachable — wp_remote_get returns an empty response.
		// Pre-branch behaviour: dropped the marker. New behaviour: keep it,
		// surface as PENDING so cron / next poll can retry.
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn(
				array(
					'response' => array( 'code' => 502 ),
					'body'     => '',
				)
			);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'PENDING', $response->get_data()['state'] );
		// Marker MUST persist so MeltReconciler can pick this up later.
		$this->assertSame( 'q_unreachable', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_explicit_unpaid_drops_marker(): void {
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q_explicit_unpaid',
				'_cashu_melt_pending_at'       => (string) ( time() - 30 ),
				'_cashu_melt_mint'             => 'https://m.example',
				'_cashu_spot_time'             => (string) time(),
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array( 'state' => 'UNPAID' ) ),
				)
			);

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		// Falls through to UNPAID/EXPIRED branch after marker drop — the
		// assertion is that the marker is gone, NOT what state name comes back
		// (the spot expiry vs UNPAID branch depends on time).
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_pending_marker_without_mint_url_skips_mint(): void {
		// Edge case: the marker is set but the mint URL is empty. Without
		// the order's mint URL we have nowhere to route the lookup; the
		// resolver must NOT fall back to the gateway's current setting
		// (that's the H1-style routing-mismatch hazard). Falls through.
		$this->stubControllerBaseline();
		$this->setUpFakeWpdb();

		$order = $this->mockOrder(
			42,
			array(
				'_cashu_melt_pending_quote_id' => 'q_x',
				'_cashu_melt_pending_at'       => (string) ( time() - 30 ),
				'_cashu_melt_mint'             => '',
				'_cashu_spot_time'             => (string) time(),
			)
		);
		$order->shouldReceive( 'is_paid' )->andReturn( false );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_remote_get' )->never();

		$controller = new ConfirmMeltQuoteController();
		$response   = $controller->confirm_melt_quote( $this->request( 42, 'k' ) );

		$this->assertSame( 'UNPAID', $response->get_data()['state'] );
	}
}
