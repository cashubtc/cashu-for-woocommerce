<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

use Cashu\WC\Gateway\CashuGateway;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Two endpoints serve order finalization:
 *
 *  POST /confirm-melt-quote
 *      Cheap polling endpoint: just answers "is this order paid?". Both legs
 *      poll this; cashu-leg orders flip to paid synchronously inside
 *      PayController, lightning-leg orders flip via the claim endpoint below.
 *      Never contacts the mint — scales with traffic.
 *
 *  POST /claim-melt-quote
 *      Lightning-leg one-shot finalizer. Called by the browser once after
 *      wallet.meltProofsBolt11() succeeds. Verifies the customer's claim:
 *
 *        - If a preimage is supplied and hashes to the stored payment_hash,
 *          marks paid with zero mint round-trips (cryptographic proof).
 *        - Otherwise (or if the hash mismatches), falls back to a single
 *          GET on the mint's melt quote and trusts the mint's PAID state.
 *
 *      Net mint traffic: 0 when preimage is provided, 1 per actual
 *      settlement when it isn't.
 */
final class ConfirmMeltQuoteController {

	private const REST_NAMESPACE = 'cashu-wc/v1';

	/**
	 * Per-order rate limit on the claim endpoint. Each attempt either passes
	 * the cryptographic preimage check (fast path, no mint hit) or talks to
	 * the mint exactly once. Bounding to 30/hour stops a leaked order_key
	 * from being used to amplify mint traffic.
	 */
	private const CLAIM_RATE_LIMIT_MAX = 30;
	private const CLAIM_RATE_LIMIT_TTL = HOUR_IN_SECONDS;

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/confirm-melt-quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'confirm_melt_quote' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'order_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'order_id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/claim-melt-quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'claim_melt_quote' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'order_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'order_id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'preimage'  => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	public function permission_callback( WP_REST_Request $request ): bool {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order_id  = (int) $request->get_param( 'order_id' );
		$order_key = (string) $request->get_param( 'order_key' );
		$order_key = sanitize_text_field( $order_key );

		if ( '' === $order_key || $order_id <= 0 ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		return $order->key_is_valid( $order_key );
	}

	/**
	 * Cheap polling endpoint. Does NOT contact the mint for orders that aren't
	 * in PENDING-melt state (both settlement paths flip is_paid() through
	 * other entry points). For orders flagged as pending by PayController,
	 * does ONE cached mint state check per poll so the order can finalize
	 * once the mint completes its LN payment.
	 */
	public function confirm_melt_quote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order = $this->load_order( $request );
		if ( $order instanceof WP_Error ) {
			return $order;
		}

		if ( $order->is_paid() ) {
			return rest_ensure_response(
				array(
					'ok'       => true,
					'state'    => 'PAID',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		// If a cashu-leg melt is mid-flight at the mint, resolve it.
		$pending_check = $this->resolve_pending_melt( $order );
		if ( null !== $pending_check ) {
			return $pending_check;
		}

		$spot_time   = absint( $order->get_meta( '_cashu_spot_time', true ) );
		$spot_expiry = $spot_time + CashuGateway::QUOTE_EXPIRY_SECS;
		if ( $spot_expiry > 0 && time() >= $spot_expiry ) {
			return rest_ensure_response(
				array(
					'ok'     => true,
					'state'  => 'EXPIRED',
					'expiry' => $spot_expiry,
				)
			);
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'state'  => 'UNPAID',
				'expiry' => $spot_expiry,
			)
		);
	}

	/**
	 * If the order carries a pending-melt marker, check the mint for the
	 * current state of that quote (cached for 10s per quote_id so concurrent
	 * browser polls share the cost). Returns:
	 *
	 *   - PAID response with redirect + marks the order paid, if mint settled
	 *   - PENDING response (state remains pending, marker preserved)
	 *   - null if the order is not in pending state, so the caller falls
	 *     through to the regular UNPAID branch
	 *
	 * Bounded: ~6 mint hits per pending minute per order (at 5s poll + 10s
	 * cache). Zero hits once the marker is cleared.
	 */
	private function resolve_pending_melt( WC_Order $order ): WP_REST_Response|WP_Error|null {
		$pending_quote_id = (string) $order->get_meta( '_cashu_melt_pending_quote_id', true );
		if ( '' === $pending_quote_id ) {
			return null;
		}

		// Use the mint the quote was issued at, not the current gateway setting,
		// so an admin-side mint change doesn't route the lookup to the wrong host.
		$order_mint = (string) $order->get_meta( '_cashu_melt_mint', true );
		if ( '' === $order_mint ) {
			return null;
		}

		$cache_key = 'cashu_melt_state_' . md5( $pending_quote_id );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			$mint_response = $cached;
		} else {
			$gateway       = new CashuGateway();
			$mint_response = $gateway->fetch_melt_quote_state_safely( $pending_quote_id, $order_mint );
			set_transient( $cache_key, $mint_response, 10 );
		}

		$state = isset( $mint_response['state'] ) ? (string) $mint_response['state'] : '';

		if ( 'PAID' === $state ) {
			$preimage = isset( $mint_response['payment_preimage'] ) && is_string( $mint_response['payment_preimage'] )
				? $mint_response['payment_preimage']
				: '';
			$amount   = isset( $mint_response['amount'] ) ? (string) $mint_response['amount'] : '';

			$this->mark_paid( $order, $pending_quote_id, $preimage, $amount );
			$order->delete_meta_data( '_cashu_melt_pending_quote_id' );
			$order->delete_meta_data( '_cashu_melt_pending_at' );
			$order->save();
			delete_transient( $cache_key );

			return rest_ensure_response(
				array(
					'ok'       => true,
					'state'    => 'PAID',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		if ( 'PENDING' === $state ) {
			return rest_ensure_response(
				array(
					'ok'    => true,
					'state' => 'PENDING',
				)
			);
		}

		// UNPAID or unknown — the mint either gave up routing or we
		// couldn't reach it. Drop the marker so the order falls back to
		// the regular UNPAID/EXPIRED flow on the next poll. The proofs
		// (if returned to the wallet by the mint) are the customer's
		// to retry with.
		$order->delete_meta_data( '_cashu_melt_pending_quote_id' );
		$order->delete_meta_data( '_cashu_melt_pending_at' );
		$order->save();
		delete_transient( $cache_key );
		return null;
	}

	/**
	 * Browser claim. Verifies the preimage cryptographically when supplied;
	 * falls back to a single mint round-trip when the client doesn't supply
	 * one. A supplied preimage that doesn't hash to the stored payment_hash
	 * is treated as a lying client and rejected — we do NOT then fall back
	 * to the mint, otherwise a leaked order_key would let an attacker
	 * amplify mint traffic via this endpoint.
	 */
	public function claim_melt_quote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order = $this->load_order( $request );
		if ( $order instanceof WP_Error ) {
			return $order;
		}

		if ( $order->is_paid() ) {
			return rest_ensure_response(
				array(
					'ok'       => true,
					'state'    => 'PAID',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		// Rate-limit before any work that could touch the mint.
		if ( ! $this->check_claim_rate_limit( $order->get_id() ) ) {
			return new WP_Error( 'cashu_rate_limited', 'Too many attempts.', array( 'status' => 429 ) );
		}

		$quote_id = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		if ( '' === $quote_id ) {
			return new WP_Error( 'cashu_no_quote', 'Missing melt quote id on order.', array( 'status' => 400 ) );
		}

		$preimage     = trim( (string) $request->get_param( 'preimage' ) );
		$payment_hash = (string) $order->get_meta( '_cashu_payment_hash', true );

		// If the client supplied a preimage, we MUST verify it cryptographically
		// before doing anything else. A correct preimage marks the order paid
		// with zero mint traffic. An incorrect preimage is treated as malicious
		// or buggy — reject outright; do not silently fall back to a mint hit
		// (that's the amplification vector).
		if ( '' !== $preimage ) {
			if ( '' !== $payment_hash && $this->preimage_matches( $preimage, $payment_hash ) ) {
				$this->mark_paid( $order, $quote_id, $preimage, null );
				return rest_ensure_response(
					array(
						'ok'       => true,
						'state'    => 'PAID',
						'redirect' => $order->get_checkout_order_received_url(),
					)
				);
			}
			return new WP_Error( 'cashu_bad_preimage', 'Preimage does not match invoice payment hash.', array( 'status' => 400 ) );
		}

		// No preimage supplied — ask the mint once. Use the order's stored
		// mint URL, not the current setting, so an admin-side mint change
		// doesn't route this to the wrong host.
		$order_mint = (string) $order->get_meta( '_cashu_melt_mint', true );
		if ( '' === $order_mint ) {
			return new WP_Error( 'cashu_no_mint', 'Order has no recorded mint URL.', array( 'status' => 500 ) );
		}

		$url = rtrim( $order_mint, '/' ) . '/v1/melt/quote/bolt11/' . rawurlencode( $quote_id );
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			Logger::debug( 'Mint quote state request failed: ' . $res->get_error_message() );
			return new WP_Error( 'cashu_mint_error', 'Failed to query mint quote state.', array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $code ) {
			Logger::debug( 'Mint quote state HTTP ' . $code . ' body: ' . $body );
			return new WP_Error( 'cashu_mint_http', 'Mint returned a non 200 response.', array( 'status' => 502 ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['state'] ) ) {
			Logger::debug( 'Mint quote state invalid JSON: ' . $body );
			return new WP_Error( 'cashu_mint_json', 'Mint returned invalid JSON.', array( 'status' => 502 ) );
		}

		$state           = (string) $data['state'];
		$mint_preimage   = ( isset( $data['payment_preimage'] ) && is_string( $data['payment_preimage'] ) )
			? $data['payment_preimage']
			: '';
		$reported_amount = isset( $data['amount'] ) ? (string) $data['amount'] : '';

		if ( 'PAID' !== $state ) {
			return rest_ensure_response(
				array(
					'ok'    => true,
					'state' => $state,
				)
			);
		}

		$this->mark_paid( $order, $quote_id, $mint_preimage, $reported_amount );
		return rest_ensure_response(
			array(
				'ok'       => true,
				'state'    => 'PAID',
				'redirect' => $order->get_checkout_order_received_url(),
			)
		);
	}

	private function check_claim_rate_limit( int $order_id ): bool {
		$key   = 'cashu_wc_claim_attempts_' . $order_id;
		$count = (int) get_transient( $key );
		if ( $count >= self::CLAIM_RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::CLAIM_RATE_LIMIT_TTL );
		return true;
	}

	/**
	 * sha256(preimage) == payment_hash, both compared in lowercase hex.
	 */
	private function preimage_matches( string $preimage_hex, string $payment_hash_hex ): bool {
		$preimage_hex     = strtolower( $preimage_hex );
		$payment_hash_hex = strtolower( $payment_hash_hex );
		if ( ! ctype_xdigit( $preimage_hex ) || ! ctype_xdigit( $payment_hash_hex ) ) {
			return false;
		}
		$bytes = hex2bin( $preimage_hex );
		if ( false === $bytes ) {
			return false;
		}
		return hash_equals( $payment_hash_hex, hash( 'sha256', $bytes ) );
	}

	private function mark_paid( WC_Order $order, string $quote_id, ?string $preimage, ?string $amount ): void {
		if ( $order->is_paid() ) {
			return;
		}

		if ( null !== $preimage && '' !== $preimage ) {
			$order->update_meta_data( '_cashu_payment_preimage', sanitize_text_field( $preimage ) );
		}
		$order->payment_complete( $quote_id );

		$lightning_address = (string) get_option( 'cashu_lightning_address', '' );
		$amount_for_note   = ( null !== $amount && '' !== $amount )
			? $amount
			: (string) absint( $order->get_meta( '_cashu_melt_total', true ) );

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: BTC Symbol, %2$s: amount, %3$s: Lightning Address, %4$s: Melt Quote ID, %5$s: Payment preimage */
				__( "Cashu payment: %1\$s%2\$s\nSent to: %3\$s\nMelt quote: %4\$s\nPayment preimage: %5\$s", 'cashu-for-woocommerce' ),
				CASHU_WC_BIP177_SYMBOL,
				$amount_for_note,
				$lightning_address,
				$quote_id,
				(string) ( $preimage ?? '' )
			)
		);
	}

	/**
	 * Shared order lookup + gateway check used by both endpoints. Permission
	 * callback has already validated the order_key.
	 */
	private function load_order( WP_REST_Request $request ): WC_Order|WP_Error {
		$order_id = (int) $request->get_param( 'order_id' );
		if ( $order_id <= 0 ) {
			return new WP_Error( 'cashu_no_order', 'Order not found.', array( 'status' => 404 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'cashu_no_order', 'Order not found.', array( 'status' => 404 ) );
		}

		if ( $order->get_payment_method() !== 'cashu_default' ) {
			return new WP_Error( 'cashu_wrong_gateway', 'Order is not using Cashu.', array( 'status' => 400 ) );
		}

		return $order;
	}
}
