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
	 * Hard cap on proof count per POST. Any reasonable wallet uses an optimal
	 * (popcount) split — 64 proofs covers amounts up to 2^64-1 sats. A wallet
	 * sending many more than that is either using 1-sat denominations (which
	 * would rack up mint input fees that the merchant melt buffer cannot
	 * absorb) or is broken/hostile. Rejecting here is cheaper than letting it
	 * fail at the mint.
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

		// Rate limit before anything potentially expensive.
		if ( ! $this->check_rate_limit( $order_id ) ) {
			return new WP_Error( 'cashu_rate_limited', 'Too many attempts.', array( 'status' => 429 ) );
		}

		$expected_id = self::payment_id_for( $order_id, $order_key );

		// Idempotent fast path.
		if ( $order->is_paid() ) {
			return rest_ensure_response(
				array(
					'status' => 'ok',
					'id'     => $expected_id,
				)
			);
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

		// Hand off to the mint.
		$gateway = new CashuGateway();
		try {
			$mint_response = $gateway->request_melt_bolt11( $quote_id, $proofs );
		} catch ( \Throwable $e ) {
			Logger::debug( 'Cashu melt failed: ' . $e->getMessage() );
			return new WP_Error( 'cashu_mint_error', 'Mint melt failed.', array( 'status' => 502 ) );
		}

		$state = isset( $mint_response['state'] )
			? (string) $mint_response['state']
			: ( ! empty( $mint_response['paid'] ) ? 'PAID' : '' );

		if ( 'PAID' !== $state ) {
			Logger::debug( 'Mint returned non-PAID state: ' . wp_json_encode( $mint_response ) );
			return new WP_Error( 'cashu_unpaid', 'Mint did not settle the invoice.', array( 'status' => 502 ) );
		}

		// Persist preimage + change.
		if ( isset( $mint_response['payment_preimage'] ) && is_string( $mint_response['payment_preimage'] ) && '' !== $mint_response['payment_preimage'] ) {
			$order->update_meta_data( '_cashu_payment_preimage', sanitize_text_field( $mint_response['payment_preimage'] ) );
		}
		$change = isset( $mint_response['change'] ) && is_array( $mint_response['change'] ) ? $mint_response['change'] : array();

		$order->payment_complete( $quote_id );

		$lightning_address = (string) get_option( 'cashu_lightning_address', '' );
		$paid_amount       = isset( $mint_response['amount'] )
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
				(string) ( $mint_response['payment_preimage'] ?? '' )
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

	private function same_mint( string $a, string $b ): bool {
		$norm = static function ( string $s ): string {
			$s = rtrim( trim( $s ), '/' );
			return strtolower( $s );
		};
		return $norm( $a ) === $norm( $b );
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
