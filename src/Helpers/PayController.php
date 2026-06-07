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
		$order_id  = (int) $request->get_param( 'order_id' );
		$order_key = sanitize_text_field( (string) $request->get_param( 'order_key' ) );

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
		if ( ! OrderLock::acquire( $order_id, 'pay', self::PAY_LOCK_TTL ) ) {
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
			$gateway = new CashuGateway();
			try {
				$mint_response = $gateway->request_melt_bolt11( $quote_id, $proofs, $trusted_mint );
			} catch ( \Throwable $e ) {
				Logger::debug( 'Cashu melt failed for order ' . $order->get_id() . ', quote ' . $quote_id . ': ' . $e->getMessage() . ' — probing mint state' );
				$probed       = $gateway->fetch_melt_quote_state_safely( $quote_id, $trusted_mint );
				$probed_state = isset( $probed['state'] ) ? (string) $probed['state'] : '';

				if ( 'PAID' === $probed_state ) {
					return $this->finalise_paid( $order, $quote_id, $probed, $expected_id, $expected_amount );
				}
				if ( 'PENDING' === $probed_state ) {
					// Marker already set by pre-stage. Refresh timestamp.
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
					// Mint never consumed the proofs — they're still spendable. Drop
					// the marker so future polls don't waste a mint hit on a dead quote.
					// Stamp the last-attempt timestamp so a returning customer's
					// receipt page can surface "previous attempt didn't reach the
					// mint" rather than silently reverting to "Waiting for payment".
					$order->delete_meta_data( '_cashu_melt_pending_quote_id' );
					$order->delete_meta_data( '_cashu_melt_pending_at' );
					$order->update_meta_data( '_cashu_last_payment_attempt_at', time() );
					$order->save();
					return new WP_Error( 'cashu_mint_error', 'Mint melt failed.', array( 'status' => 502 ) );
				}
				// Unknown / probe also failed — KEEP the marker so confirm_melt_quote
				// and MeltReconciler can keep trying.
				return new WP_Error( 'cashu_mint_error', 'Mint melt failed.', array( 'status' => 502 ) );
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
				$probed       = $gateway->fetch_melt_quote_state_safely( $quote_id, $trusted_mint );
				$probed_state = isset( $probed['state'] ) ? (string) $probed['state'] : '';

				if ( 'PAID' === $probed_state ) {
					return $this->finalise_paid( $order, $quote_id, $probed, $expected_id, $expected_amount );
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
				return new WP_Error( 'cashu_unpaid', 'Mint did not settle the invoice.', array( 'status' => 502 ) );
			}

			return $this->finalise_paid( $order, $quote_id, $mint_response, $expected_id, $expected_amount );
		} finally {
			OrderLock::release( $order_id, 'pay' );
		}
	}

	/**
	 * Finalise an order against a PAID mint response. Reused by the direct-PAID
	 * branch and the reconciliation probe so they share one source of truth on
	 * the preimage check, order note, and marker clear.
	 *
	 * @param array $mint_response Mint's reply (decoded). Must carry state=PAID.
	 */
	private function finalise_paid( \WC_Order $order, string $quote_id, array $mint_response, string $expected_id, int $expected_amount ): \WP_REST_Response {
		// Persist preimage + change. Verify the mint-supplied preimage
		// against the stored payment_hash before storing it — a misbehaving
		// or compromised mint could otherwise poison the audit trail. A
		// mismatch isn't fatal (the proofs ARE consumed at the mint, so
		// the merchant is paid) but the recorded preimage should not lie.
		$raw_preimage      = isset( $mint_response['payment_preimage'] ) && is_string( $mint_response['payment_preimage'] )
			? $mint_response['payment_preimage']
			: '';
		$stored_hash       = (string) $order->get_meta( '_cashu_payment_hash', true );
		$verified_preimage = '';
		if ( '' !== $raw_preimage ) {
			if ( '' === $stored_hash || Bolt11::preimageMatches( $raw_preimage, $stored_hash ) ) {
				$verified_preimage = $raw_preimage;
				$order->update_meta_data( '_cashu_payment_preimage', sanitize_text_field( $raw_preimage ) );
			} else {
				Logger::error( 'PayController: mint preimage does not match invoice hash for order ' . $order->get_id() );
			}
		}
		$change = isset( $mint_response['change'] ) && is_array( $mint_response['change'] ) ? $mint_response['change'] : array();

		$order->delete_meta_data( '_cashu_melt_pending_quote_id' );
		$order->delete_meta_data( '_cashu_melt_pending_at' );
		$order->delete_meta_data( '_cashu_last_payment_attempt_at' );

		$order->payment_complete( $quote_id );

		// Prefer the LN address snapshotted at quote creation; fall back
		// to the current option for legacy orders that pre-date that snapshot.
		$lightning_address = (string) $order->get_meta( '_cashu_invoice_ln_address', true );
		if ( '' === $lightning_address ) {
			$lightning_address = (string) get_option( 'cashu_lightning_address', '' );
		}
		$paid_amount = isset( $mint_response['amount'] )
			? (string) $mint_response['amount']
			: (string) $expected_amount;

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: BTC Symbol, %2$s: amount, %3$s: Lightning Address, %4$s: Melt Quote ID, %5$s: Payment preimage */
				__( "Cashu payment (NUT-18): %1\$s%2\$s\nSent to: %3\$s\nMelt quote: %4\$s\nPayment preimage: %5\$s", 'cashu-for-woocommerce' ),
				CASHU_WC_BIP177_SYMBOL,
				$paid_amount,
				$lightning_address,
				$quote_id,
				$verified_preimage
			)
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
	 * Compare two mint URLs in a way that matches the client-side
	 * sameMint() (URL.origin + pathname-without-trailing-slash). Delegates
	 * to the shared normaliser on CashuGateway so the wallet→server
	 * boundary and internal admin-mint comparisons agree on what
	 * "same mint" means.
	 */
	private function same_mint( string $a, string $b ): bool {
		return CashuGateway::normalize_mint_url( $a ) === CashuGateway::normalize_mint_url( $b );
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
