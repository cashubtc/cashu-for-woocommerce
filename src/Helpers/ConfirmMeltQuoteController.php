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
	 * Cheap polling endpoint. Does NOT contact the mint — both settlement
	 * paths flip $order->is_paid() through other entry points.
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
	 * Browser claim. Verifies the preimage cryptographically when supplied;
	 * falls back to a single mint round-trip when not.
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

		$quote_id = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		if ( '' === $quote_id ) {
			return new WP_Error( 'cashu_no_quote', 'Missing melt quote id on order.', array( 'status' => 400 ) );
		}

		$preimage     = trim( (string) $request->get_param( 'preimage' ) );
		$payment_hash = (string) $order->get_meta( '_cashu_payment_hash', true );

		// Fast path: preimage hashes to the invoice's payment_hash.
		if ( '' !== $preimage && '' !== $payment_hash && $this->preimage_matches( $preimage, $payment_hash ) ) {
			$this->mark_paid( $order, $quote_id, $preimage, null );
			return rest_ensure_response(
				array(
					'ok'       => true,
					'state'    => 'PAID',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		// Fallback: ask the mint once.
		$trusted_mint = trim( (string) get_option( 'cashu_trusted_mint', '' ) );
		if ( '' === $trusted_mint ) {
			return new WP_Error( 'cashu_no_mint', 'Trusted mint not configured.', array( 'status' => 500 ) );
		}

		$url = rtrim( $trusted_mint, '/' ) . '/v1/melt/quote/bolt11/' . rawurlencode( $quote_id );
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
