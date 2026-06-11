<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\PayController;
use Cashu\WC\Tests\IntegrationTestCase;
use Mockery\MockInterface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The pay endpoint's request-validation ladder and the mint-response
 * branches (PENDING, legacy paid-bool, replay block). Each rung must
 * reject with its specific error code so wallets get actionable feedback
 * — and so nothing past the failed rung (mint calls!) ever runs.
 */
final class PayControllerValidationTest extends IntegrationTestCase {

	private const MINT = 'https://mint.example';

	private function stubBaseline(): void {
		Functions\when( 'is_wp_error' )->alias( static fn ( $t ): bool => $t instanceof WP_Error );
		Functions\when( 'rest_ensure_response' )->alias( static fn ( $d ) => new WP_REST_Response( $d ) );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_retrieve_response_code' )
			->alias( static fn ( $r ): int => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )
			->alias( static fn ( $r ): string => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	/** Order pre-wired to pass every guard up to the payload checks. */
	private function payableOrder( array $meta = array() ): MockInterface {
		$order = $this->mockOrder(
			42,
			array_merge(
				array(
					'_cashu_spot_time'     => (string) time(),
					'_cashu_melt_mint'     => self::MINT,
					'_cashu_melt_total'    => '1000',
					'_cashu_melt_quote_id' => 'q1',
				),
				$meta
			)
		);
		$order->shouldReceive( 'key_is_valid' )->andReturn( true )->byDefault();
		$order->shouldReceive( 'is_paid' )->andReturn( false )->byDefault();
		return $order;
	}

	private function request( array $params, ?array $body = null, ?string $raw_body = null ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_params( $params );
		if ( null !== $raw_body ) {
			$req->set_body( $raw_body );
		} elseif ( null !== $body ) {
			$req->set_body( (string) json_encode( $body ) );
		}
		return $req;
	}

	/** NUT-18 payload that passes every check; override fields to break one. */
	private function validPayload( array $overrides = array() ): array {
		return array_merge(
			array(
				'mint'   => self::MINT,
				'unit'   => 'sat',
				'id'     => PayController::payment_id_for( 42, 'k' ),
				'proofs' => array(
					array( 'id' => '009a', 'amount' => 1024, 'secret' => 's', 'C' => 'c' ),
				),
			),
			$overrides
		);
	}

	private function pay( WP_REST_Request $req ) {
		return ( new PayController() )->pay( $req );
	}

	private function assertErrorCode( string $expected, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_code() );
	}

	// ── the validation ladder ────────────────────────────────────────────

	public function test_rejects_bad_order_reference(): void {
		$this->stubBaseline();
		$this->assertErrorCode(
			'cashu_bad_request',
			$this->pay( $this->request( array( 'order_id' => 0, 'order_key' => 'k' ) ) )
		);
	}

	public function test_rejects_unknown_order(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( false );
		$this->assertErrorCode(
			'cashu_no_order',
			$this->pay( $this->request( array( 'order_id' => 42, 'order_key' => 'k' ) ) )
		);
	}

	public function test_rejects_wrong_order_key(): void {
		$this->stubBaseline();
		$order = $this->payableOrder();
		$order->shouldReceive( 'key_is_valid' )->andReturn( false );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->assertErrorCode(
			'cashu_bad_key',
			$this->pay( $this->request( array( 'order_id' => 42, 'order_key' => 'wrong' ) ) )
		);
	}

	public function test_rejects_non_cashu_order(): void {
		$this->stubBaseline();
		$order = $this->payableOrder();
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'stripe' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->assertErrorCode(
			'cashu_wrong_gateway',
			$this->pay( $this->request( array( 'order_id' => 42, 'order_key' => 'k' ) ) )
		);
	}

	public function test_already_paid_order_returns_ok_without_consuming_rate_budget(): void {
		$this->stubBaseline();
		$order = $this->payableOrder();
		$order->shouldReceive( 'is_paid' )->andReturn( true );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		// A retrying wallet must hit the idempotent path before the rate
		// limiter, or its retries could turn "already settled" into a 429.
		Functions\expect( 'get_transient' )->never();

		$result = $this->pay( $this->request( array( 'order_id' => 42, 'order_key' => 'k' ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 'ok', $result->get_data()['status'] );
		$this->assertSame( PayController::payment_id_for( 42, 'k' ), $result->get_data()['id'] );
	}

	public function test_rate_limited_after_max_attempts(): void {
		$this->stubBaseline();
		Functions\when( 'get_transient' )->justReturn( 30 ); // RATE_LIMIT_MAX
		$order = $this->payableOrder();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->assertErrorCode(
			'cashu_rate_limited',
			$this->pay( $this->request( array( 'order_id' => 42, 'order_key' => 'k' ) ) )
		);
	}

	public function test_missing_spot_quote_reads_as_expired(): void {
		// No _cashu_spot_time means setup never completed — the order must
		// not accept payment, exactly like a stale quote.
		$this->stubBaseline();
		$order = $this->payableOrder( array( '_cashu_spot_time' => '' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->assertErrorCode(
			'cashu_expired',
			$this->pay( $this->request( array( 'order_id' => 42, 'order_key' => 'k' ) ) )
		);
	}

	public function test_rejects_non_json_body(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder() );

		$this->assertErrorCode(
			'cashu_bad_body',
			$this->pay( $this->request( array( 'order_id' => 42, 'order_key' => 'k' ), null, 'not-json{' ) )
		);
	}

	public function test_rejects_non_sat_unit(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder() );

		$this->assertErrorCode(
			'cashu_bad_unit',
			$this->pay(
				$this->request(
					array( 'order_id' => 42, 'order_key' => 'k' ),
					$this->validPayload( array( 'unit' => 'usd' ) )
				)
			)
		);
	}

	public function test_rejects_proofs_from_foreign_mint(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder() );

		$this->assertErrorCode(
			'cashu_bad_mint',
			$this->pay(
				$this->request(
					array( 'order_id' => 42, 'order_key' => 'k' ),
					$this->validPayload( array( 'mint' => 'https://evil.example' ) )
				)
			)
		);
	}

	public function test_rejects_payment_id_for_other_order(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder() );

		$this->assertErrorCode(
			'cashu_bad_id',
			$this->pay(
				$this->request(
					array( 'order_id' => 42, 'order_key' => 'k' ),
					$this->validPayload( array( 'id' => PayController::payment_id_for( 43, 'other' ) ) )
				)
			)
		);
	}

	public function test_rejects_empty_proofs(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder() );

		$this->assertErrorCode(
			'cashu_no_proofs',
			$this->pay(
				$this->request(
					array( 'order_id' => 42, 'order_key' => 'k' ),
					$this->validPayload( array( 'proofs' => array() ) )
				)
			)
		);
	}

	public function test_rejects_oversized_proof_sets(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder() );

		$proofs = array_fill( 0, 65, array( 'amount' => 1 ) ); // MAX_PROOFS_PER_PAYMENT + 1

		$this->assertErrorCode(
			'cashu_too_many_proofs',
			$this->pay(
				$this->request(
					array( 'order_id' => 42, 'order_key' => 'k' ),
					$this->validPayload( array( 'proofs' => $proofs ) )
				)
			)
		);
	}

	/** @dataProvider malformedProofs */
	public function test_rejects_malformed_proofs( array $proofs ): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder() );

		$this->assertErrorCode(
			'cashu_bad_proof',
			$this->pay(
				$this->request(
					array( 'order_id' => 42, 'order_key' => 'k' ),
					$this->validPayload( array( 'proofs' => $proofs ) )
				)
			)
		);
	}

	public static function malformedProofs(): array {
		return array(
			'non-array proof entry'     => array( array( 'just-a-string' ) ),
			'non-numeric proof amount'  => array( array( array( 'amount' => 'NaN' ) ) ),
		);
	}

	public function test_rejects_underfunded_proofs_with_amounts_in_data(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder() );

		$result = $this->pay(
			$this->request(
				array( 'order_id' => 42, 'order_key' => 'k' ),
				$this->validPayload( array( 'proofs' => array( array( 'amount' => 999 ) ) ) )
			)
		);

		$this->assertErrorCode( 'cashu_underfunded', $result );
		$this->assertSame( 1000, $result->get_error_data()['expected'] );
		$this->assertSame( 999, $result->get_error_data()['received'] );
	}

	public function test_rejects_order_with_no_expected_amount(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder( array( '_cashu_melt_total' => '' ) ) );

		$this->assertErrorCode(
			'cashu_no_amount',
			$this->pay(
				$this->request( array( 'order_id' => 42, 'order_key' => 'k' ), $this->validPayload() )
			)
		);
	}

	public function test_rejects_order_with_no_melt_quote(): void {
		$this->stubBaseline();
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder( array( '_cashu_melt_quote_id' => '' ) ) );

		$this->assertErrorCode(
			'cashu_no_quote',
			$this->pay(
				$this->request( array( 'order_id' => 42, 'order_key' => 'k' ), $this->validPayload() )
			)
		);
	}

	public function test_concurrent_payment_returns_409_in_flight(): void {
		$this->stubBaseline();
		$this->setUpFakeWpdb( 0 ); // lock already held by another request
		Functions\when( 'wc_get_order' )->justReturn( $this->payableOrder() );

		$this->assertErrorCode(
			'cashu_in_flight',
			$this->pay(
				$this->request( array( 'order_id' => 42, 'order_key' => 'k' ), $this->validPayload() )
			)
		);
	}

	// ── mint-response branches ───────────────────────────────────────────

	public function test_pending_melt_returns_pending_and_keeps_marker(): void {
		// PENDING = proofs accepted, LN payment in flight. Telling the
		// wallet "failed" here would be a lie with the proofs locked at the
		// mint; it must hear accepted-but-in-flight, and the marker must
		// survive for the poll endpoint / reconciler to finish the job.
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$order = $this->payableOrder();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'state' => 'PENDING' ) ),
			)
		);

		$result = $this->pay(
			$this->request( array( 'order_id' => 42, 'order_key' => 'k' ), $this->validPayload() )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 'pending', $result->get_data()['status'] );
		$this->assertSame( 'q1', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}

	public function test_legacy_paid_bool_finalises_like_state_paid(): void {
		// Pre-NUT-23 mints reply {"paid": true} with no state field.
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$order = $this->payableOrder();
		$order->shouldReceive( 'payment_complete' )->once()->with( 'q1' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'paid' => true, 'change' => array() ) ),
			)
		);

		$result = $this->pay(
			$this->request( array( 'order_id' => 42, 'order_key' => 'k' ), $this->validPayload() )
		);

		$this->assertSame( 'ok', $result->get_data()['status'] );
	}

	public function test_replayed_settlement_on_cancelled_order_returns_change_without_completing(): void {
		// Order settled once, admin cancelled it, wallet retries with the
		// same proofs: the mint re-proves PAID but payment_complete must
		// NOT fire again — only the wallet's change comes back.
		$this->stubBaseline();
		$this->setUpFakeWpdb();
		$order = $this->payableOrder( array( '_cashu_paid_once' => (string) ( time() - 600 ) ) );
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldReceive( 'payment_complete' )->never();
		$order->shouldReceive( 'add_order_note' )->once()->andReturn( 1 ); // replay-block note
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$change = array( array( 'id' => '009a', 'amount' => 24, 'C_' => 'cc' ) );
		Functions\expect( 'wp_remote_post' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'state' => 'PAID', 'change' => $change ) ),
			)
		);

		$result = $this->pay(
			$this->request( array( 'order_id' => 42, 'order_key' => 'k' ), $this->validPayload() )
		);

		$this->assertSame( 'ok', $result->get_data()['status'] );
		$this->assertSame( $change, $result->get_data()['change'] );
		// Pending markers cleared so the reconciler doesn't chase a ghost.
		$this->assertSame( '', $order->get_meta( '_cashu_melt_pending_quote_id' ) );
	}
}
