<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

use Cashu\WC\Gateway\CashuGateway;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * NUT-18 cashu payment receiver.
 *
 * Cashu wallets POST a PaymentRequestPayload here. The endpoint validates the payload
 * against the order's stored melt quote, asks the trusted mint to melt the supplied
 * proofs against that quote (settling the merchant's lightning invoice in one mint
 * round-trip), and marks the order paid.
 *
 * Idempotent: a second POST after settlement returns 200 without contacting the mint.
 */
final class PayController {

	private const REST_NAMESPACE = 'cashu-wc/v1';
	private const RATE_LIMIT_MAX = 30;
	private const RATE_LIMIT_TTL = HOUR_IN_SECONDS;

	/**
	 * In-flight lock TTL covering the mint round-trip + meta writes.
	 * Sized generously vs. a normal LN settle so a slow mint doesn't
	 * trip the stale-lock cleanup, but short enough that a crashed PHP
	 * process self-clears within a couple of minutes.
	 */
	private const PAY_LOCK_TTL = 120;

	/**
	 * Hard cap on proof count per POST. Any reasonable wallet uses an optimal
	 * (popcount) split — 64 proofs covers amounts up to 2^64-1 sats. A wallet
	 * sending many more than that is either using 1-sat denominations (which
	 * would rack up mint input fees that the merchant melt buffer cannot
	 * absorb) or is broken/hostile. Rejecting here is cheaper than letting it
	 * fail at the mint.
	 *
	 * Note: this assumes a power-of-two denomination set, which is what stock
	 * sat mints use today. A NUT-15 mint with bespoke denominations could
	 * legitimately need more proofs to express a payment — revisit if such a
	 * mint enters production use.
	 */
	private const MAX_PROOFS_PER_PAYMENT = 64;

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/pay/(?P<order_id>\d+)/(?P<order_key>[A-Za-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pay' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'order_id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'order_key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Deterministic payment id derived from the order. Identical to the value placed in
	 * the NUT-18 PaymentRequest that the wallet scanned. Lets the server reject obviously
	 * mismatched payloads (e.g. a request bound to a different order).
	 */
	public static function payment_id_for( int $order_id, string $order_key ): string {
		return substr( wp_hash( $order_id . '|' . $order_key . '|cashu_payment_id' ), 0, 16 );
	}

	public function pay( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Pin the order reference to the URL captures. get_param() lets a
		// same-named key in the JSON body shadow the route values (body wins
		// in WP's parameter order), which can't cross orders — auth binds to
		// the order_key — but would make logs disagree with the route hit.
		$url_params = $request->get_url_params();
		$order_id   = (int) ( $url_params['order_id'] ?? $request->get_param( 'order_id' ) );
		$order_key  = sanitize_text_field( (string) ( $url_params['order_key'] ?? $request->get_param( 'order_key' ) ) );

		if ( $order_id <= 0 || '' === $order_key ) {
			return new WP_Error( 'cashu_bad_request', 'Bad order reference.', array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'cashu_no_wc', 'WooCommerce unavailable.', array( 'status' => 500 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'cashu_no_order', 'Order not found.', array( 'status' => 404 ) );
		}

		if ( ! $order->key_is_valid( $order_key ) ) {
			return new WP_Error( 'cashu_bad_key', 'Order key mismatch.', array( 'status' => 403 ) );
		}

		if ( $order->get_payment_method() !== 'cashu_default' ) {
			return new WP_Error( 'cashu_wrong_gateway', 'Order is not using Cashu.', array( 'status' => 400 ) );
		}

		$expected_id = self::payment_id_for( $order_id, $order_key );

		// Idempotent fast path — must run before the rate-limit check so a
		// retrying wallet that already settled the order never gets a 429
		// back instead of the documented "already settled" 200.
		if ( $order->is_paid() ) {
			return rest_ensure_response(
				array(
					'status' => 'ok',
					'id'     => $expected_id,
				)
			);
		}

		// Rate limit before anything potentially expensive.
		if ( ! $this->check_rate_limit( $order_id ) ) {
			return new WP_Error( 'cashu_rate_limited', 'Too many attempts.', array( 'status' => 429 ) );
		}

		// Spot quote still valid?
		$spot_time   = absint( $order->get_meta( '_cashu_spot_time', true ) );
		$spot_expiry = $spot_time + CashuGateway::QUOTE_EXPIRY_SECS;
		if ( $spot_expiry > 0 && time() >= $spot_expiry ) {
			return new WP_Error( 'cashu_expired', 'Payment window expired.', array( 'status' => 410 ) );
		}

		// Body — accept the NUT-18 PaymentRequestPayload shape.
		$payload = $this->read_json_body( $request );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'cashu_bad_body', 'Body is not valid JSON.', array( 'status' => 400 ) );
		}

		$body_mint = isset( $payload['mint'] ) ? trim( (string) $payload['mint'] ) : '';
		$body_unit = isset( $payload['unit'] ) ? trim( (string) $payload['unit'] ) : '';
		$body_id   = isset( $payload['id'] ) ? (string) $payload['id'] : '';
		$proofs    = isset( $payload['proofs'] ) && is_array( $payload['proofs'] ) ? $payload['proofs'] : array();

		if ( 'sat' !== $body_unit ) {
			return new WP_Error( 'cashu_bad_unit', 'Unit must be sat.', array( 'status' => 400 ) );
		}

		$trusted_mint = (string) $order->get_meta( '_cashu_melt_mint', true );
		if ( '' === $trusted_mint || ! $this->same_mint( $body_mint, $trusted_mint ) ) {
			// Strip CR/LF before interpolating so a wallet that sends
			// "https://evil/\n[CRIT] forged log line" cannot inject fake
			// log entries above this one. The mint is otherwise echoed
			// verbatim so operators can see exactly what the client sent.
			$safe_body    = str_replace( array( "\r", "\n" ), ' ', $body_mint );
			$safe_trusted = str_replace( array( "\r", "\n" ), ' ', $trusted_mint );
			Logger::debug(
				'PayController::pay 400 cashu_bad_mint on order ' . $order_id
				. ' — wallet sent "' . $safe_body . '", expected "' . $safe_trusted . '"'
			);
			return new WP_Error( 'cashu_bad_mint', 'Proofs must originate at the merchant mint.', array( 'status' => 400 ) );
		}

		if ( ! hash_equals( $expected_id, $body_id ) ) {
			return new WP_Error( 'cashu_bad_id', 'Payment id mismatch.', array( 'status' => 400 ) );
		}

		if ( empty( $proofs ) ) {
			return new WP_Error( 'cashu_no_proofs', 'No proofs supplied.', array( 'status' => 400 ) );
		}

		if ( count( $proofs ) > self::MAX_PROOFS_PER_PAYMENT ) {
			return new WP_Error(
				'cashu_too_many_proofs',
				'Too many proofs; use a wallet that produces an optimal split.',
				array(
					'status' => 400,
					'max'    => self::MAX_PROOFS_PER_PAYMENT,
				)
			);
		}

		$proof_sum = 0;
		foreach ( $proofs as $p ) {
			if ( ! is_array( $p ) ) {
				return new WP_Error( 'cashu_bad_proof', 'Malformed proof.', array( 'status' => 400 ) );
			}
			$raw_amt = $p['amount'] ?? null;
			if ( ! is_numeric( $raw_amt ) ) {
				return new WP_Error( 'cashu_bad_proof', 'Proof amount is not numeric.', array( 'status' => 400 ) );
			}
			$proof_sum += (int) $raw_amt;
		}

		$expected_amount = absint( $order->get_meta( '_cashu_melt_total', true ) );
		if ( $expected_amount <= 0 ) {
			return new WP_Error( 'cashu_no_amount', 'Order has no expected amount.', array( 'status' => 500 ) );
		}
		if ( $proof_sum < $expected_amount ) {
			return new WP_Error(
				'cashu_underfunded',
				'Proofs do not cover the expected amount.',
				array(
					'status'   => 400,
					'expected' => $expected_amount,
					'received' => $proof_sum,
				)
			);
		}

		$quote_id = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		if ( '' === $quote_id ) {
			return new WP_Error( 'cashu_no_quote', 'Missing melt quote on order.', array( 'status' => 500 ) );
		}

		// Serialise concurrent POSTs against the same order. Two wallets
		// posting near-simultaneously must not both pass the is_paid()
		// check above and both hand proof sets to the mint — the second
		// quote-melt POST would be rejected (single-use quote) but the
		// wallet's proofs may end up in an ambiguous state at the mint.
		$lock_token = OrderLock::acquire( $order_id, 'pay', self::PAY_LOCK_TTL );
		if ( null === $lock_token ) {
			Logger::debug( 'PayController::pay 409 cashu_in_flight on order ' . $order_id );
			return new WP_Error(
				'cashu_in_flight',
				'Another payment for this order is currently being processed.',
				array( 'status' => 409 )
			);
		}

		try {
			// Re-read the order under the lock — the previous holder may
			// have just finished settling, in which case we want the
			// idempotent fast path, not another mint call.
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return new WP_Error( 'cashu_no_order', 'Order not found.', array( 'status' => 404 ) );
			}
			if ( $order->is_paid() ) {
				return rest_ensure_response(
					array(
						'status' => 'ok',
						'id'     => $expected_id,
					)
				);
			}

			$quote_id = (string) $order->get_meta( '_cashu_melt_quote_id', true );
			if ( '' === $quote_id ) {
				return new WP_Error( 'cashu_no_quote', 'Missing melt quote on order.', array( 'status' => 500 ) );
			}

			// Pre-stage the pending marker. From here on, every code path that
			// might consume proofs at the mint leaves a durable signal — either
			// we clear it on confirmed PAID below, or we leave it for the
			// polling endpoint / MeltReconciler to resolve. This means a PHP
			// fatal, HTTP timeout, or reverse-proxy 5xx mid-melt cannot strand
			// the order in a state nothing knows to reconcile.
			$order->update_meta_data( '_cashu_melt_pending_quote_id', $quote_id );
			$order->update_meta_data( '_cashu_melt_pending_at', time() );
			$order->save();

			// Hand off to the mint. Route through the mint stored on the order,
			// not the current gateway setting — an admin-side mint change between
			// quote creation and settlement must not redirect the melt to a host
			// that doesn't know the quote.
			try {
				$mint_response = MintClient::melt( $trusted_mint, $quote_id, $proofs );
			} catch ( \Throwable $e ) {
				Logger::debug( 'Cashu melt failed for order ' . $order->get_id() . ', quote ' . $quote_id . ': ' . $e->getMessage() . ' — probing mint state' );
				return $this->resolve_unsettled_melt(
					$order,
					$quote_id,
					$trusted_mint,
					$expected_id,
					new WP_Error( 'cashu_mint_error', 'Mint melt failed.', array( 'status' => 502 ) )
				);
			}

			$state = isset( $mint_response['state'] )
				? (string) $mint_response['state']
				: ( ! empty( $mint_response['paid'] ) ? 'PAID' : '' );

			// PENDING means the mint accepted the proofs and is mid-LN-payment;
			// returning an error here would tell the wallet the payment failed
			// while the proofs are actually still locked at the mint. Instead,
			// record the pending state so the polling endpoint can detect when
			// the mint finishes settling, and reply 200 with status=pending so
			// the wallet treats the payment as accepted-but-in-flight.
			if ( 'PENDING' === $state ) {
				$order->update_meta_data( '_cashu_melt_pending_quote_id', $quote_id );
				$order->update_meta_data( '_cashu_melt_pending_at', time() );
				$order->save();
				Logger::debug( 'Cashu melt PENDING for order ' . $order->get_id() . ', quote ' . $quote_id );
				return rest_ensure_response(
					array(
						'status' => 'pending',
						'id'     => $expected_id,
					)
				);
			}

			if ( 'PAID' !== $state ) {
				// Don't dump the full mint response — even on non-PAID states the
				// body can carry sensitive fields (e.g. a partial preimage on
				// some mint impls). The state + quote_id is enough to trace.
				Logger::debug( 'Mint returned non-PAID state "' . $state . '" for order ' . $order->get_id() . ', quote ' . $quote_id . ' — probing mint state' );
				return $this->resolve_unsettled_melt(
					$order,
					$quote_id,
					$trusted_mint,
					$expected_id,
					new WP_Error( 'cashu_unpaid', 'Mint did not settle the invoice.', array( 'status' => 502 ) )
				);
			}

			return $this->finalise_paid( $order, $quote_id, $mint_response, $expected_id );
		} finally {
			OrderLock::release( $order_id, 'pay', $lock_token );
		}
	}

	/**
	 * The melt call threw or returned non-PAID: ask the mint for the quote's
	 * authoritative state and resolve the order accordingly. PAID finalises;
	 * PENDING keeps the pre-staged marker (timestamp refreshed) and tells the
	 * wallet the payment is in flight; UNPAID drops the marker (the proofs
	 * were never consumed, they're back with the wallet) and stamps the
	 * last-attempt time so the receipt page can say "previous attempt didn't
	 * reach the mint"; unknown keeps the marker so confirm_melt_quote and
	 * MeltReconciler can keep trying. Returns $failure for the UNPAID and
	 * unknown branches.
	 */
	private function resolve_unsettled_melt( \WC_Order $order, string $quote_id, string $mint_url, string $expected_id, WP_Error $failure ): WP_REST_Response|WP_Error {
		$probed       = MintClient::melt_quote_state( $mint_url, $quote_id );
		$probed_state = isset( $probed['state'] ) ? (string) $probed['state'] : '';

		if ( 'PAID' === $probed_state ) {
			return $this->finalise_paid( $order, $quote_id, $probed, $expected_id );
		}
		if ( 'PENDING' === $probed_state ) {
			$order->update_meta_data( '_cashu_melt_pending_at', time() );
			$order->save();
			return rest_ensure_response(
				array(
					'status' => 'pending',
					'id'     => $expected_id,
				)
			);
		}
		if ( 'UNPAID' === $probed_state ) {
			$order->delete_meta_data( '_cashu_melt_pending_quote_id' );
			$order->delete_meta_data( '_cashu_melt_pending_at' );
			$order->update_meta_data( '_cashu_last_payment_attempt_at', time() );
			$order->save();
		}
		return $failure;
	}

	/**
	 * Finalise an order against a PAID mint response. Reused by the direct-PAID
	 * branch and the reconciliation probe so they share one source of truth on
	 * the preimage check, order note, and marker clear.
	 *
	 * @param array $mint_response Mint's reply (decoded). Must carry state=PAID.
	 */
	private function finalise_paid( \WC_Order $order, string $quote_id, array $mint_response, string $expected_id ): \WP_REST_Response {
		// Replay guard: the melt quote is single-use, so a PAID mint state
		// here can only re-prove a settlement that already completed this
		// order once. If the admin has since cancelled/failed it, refuse to
		// re-complete (see SettlementGuard). Still return ok + change so a
		// retrying wallet gets its change proofs back.
		if ( SettlementGuard::should_block( $order ) ) {
			SettlementGuard::note_blocked( $order, $quote_id );
			$order->delete_meta_data( '_cashu_melt_pending_quote_id' );
			$order->delete_meta_data( '_cashu_melt_pending_at' );
			$order->save();
			$change = isset( $mint_response['change'] ) && is_array( $mint_response['change'] ) ? $mint_response['change'] : array();
			return rest_ensure_response(
				array(
					'status' => 'ok',
					'id'     => $expected_id,
					'change' => $change,
				)
			);
		}

		$change = isset( $mint_response['change'] ) && is_array( $mint_response['change'] ) ? $mint_response['change'] : array();

		SettlementGuard::complete(
			$order,
			$quote_id,
			( isset( $mint_response['payment_preimage'] ) && is_string( $mint_response['payment_preimage'] ) )
				? $mint_response['payment_preimage']
				: '',
			isset( $mint_response['amount'] ) ? (string) $mint_response['amount'] : '',
			/* translators: %1$s: BTC Symbol, %2$s: amount, %3$s: Lightning Address, %4$s: Melt Quote ID, %5$s: Payment preimage (truncated) */
			__( "Cashu payment (NUT-18): %1\$s%2\$s\nSent to: %3\$s\nMelt quote: %4\$s\nPayment preimage: %5\$s", 'cashu-for-woocommerce' )
		);

		return rest_ensure_response(
			array(
				'status' => 'ok',
				'id'     => $expected_id,
				'change' => $change,
			)
		);
	}

	private function read_json_body( WP_REST_Request $request ): mixed {
		$raw = $request->get_body();
		if ( '' === (string) $raw ) {
			return null;
		}
		$decoded = json_decode( (string) $raw, true );
		return $decoded;
	}

	/**
	 * Compare two mint URLs via the shared normaliser so the wallet→server
	 * boundary and internal admin-mint comparisons agree on what
	 * "same mint" means.
	 */
	private function same_mint( string $a, string $b ): bool {
		return MintClient::normalize_url( $a ) === MintClient::normalize_url( $b );
	}

	private function check_rate_limit( int $order_id ): bool {
		$key   = 'cashu_wc_pay_attempts_' . $order_id;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_TTL );
		return true;
	}
}
