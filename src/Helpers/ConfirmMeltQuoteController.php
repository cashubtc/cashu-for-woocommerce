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
 *      Does not hit the mint in the steady state. Exception: if the cashu-leg
 *      melt is in PENDING-at-mint state (PayController stashed the quote id),
 *      one cached state check per poll resolves it — bounded at ~6 mint hits
 *      per pending minute per order, zero once resolved.
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

	/**
	 * Per-order rate limit on the polling endpoint. Mint amplification is
	 * already bounded by the 10s transient cache on pending-melt lookups,
	 * but each request still hits the WP REST stack + DB. The 5s legitimate
	 * poll cadence over a 15-minute payment window is ~180 polls. 720/hour
	 * (= 12/min) leaves generous headroom for tab visibility cycles and
	 * absorbs the WS-fallback poll bursts without ever pinching a real user.
	 */
	private const CONFIRM_RATE_LIMIT_MAX = 720;
	private const CONFIRM_RATE_LIMIT_TTL = HOUR_IN_SECONDS;

	/**
	 * Maximum age of a `_cashu_melt_pending_quote_id` marker before we
	 * stop probing the mint and let MeltReconciler write a final
	 * orphan-archive note. 24 hours gives the cron sweep room to catch
	 * slow-settling melts even when the customer's tab is long closed.
	 * Customer-side amplification is bounded by the per-order confirm
	 * rate-limit (720/hr) and the mint-state transient cache (above).
	 */
	private const PENDING_MARKER_MAX_AGE = DAY_IN_SECONDS;

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

		// PAID short-circuit before the rate-limit so a polling browser that
		// just settled gets the redirect even if it ran out of budget.
		if ( $order->is_paid() ) {
			return rest_ensure_response(
				array(
					'ok'       => true,
					'state'    => 'PAID',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		if ( ! $this->check_confirm_rate_limit( $order->get_id() ) ) {
			return new WP_Error( 'cashu_rate_limited', 'Too many polls.', array( 'status' => 429 ) );
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

		// Surface the timestamp of the most recent failed payment attempt
		// (set by PayController's and resolve_pending_melt's marker-drop
		// paths) so the receipt page can show "previous attempt didn't
		// reach the mint — please try again" instead of the default
		// "Waiting for payment" placeholder. Null when there's no prior
		// attempt — fresh-page-load on a never-paid order.
		$last_attempt = absint( $order->get_meta( '_cashu_last_payment_attempt_at', true ) );
		return rest_ensure_response(
			array(
				'ok'           => true,
				'state'        => 'UNPAID',
				'expiry'       => $spot_expiry,
				'last_attempt' => $last_attempt > 0 ? $last_attempt : null,
			)
		);
	}

	/**
	 * If the order carries a pending-melt marker, check the mint for the
	 * current state of that quote (cached for 10s per quote_id so concurrent
	 * browser polls share the cost). Returns:
	 *
	 *   - PAID response with redirect + marks the order paid, if mint settled
	 *   - PENDING response (state remains pending, marker preserved) — also
	 *     returned for empty/unknown mint state so MeltReconciler can keep
	 *     retrying instead of the marker being silently dropped on a blip
	 *   - null if the mint positively returned UNPAID (marker dropped) or
	 *     the marker was missing/aged/has no mint URL, so the caller falls
	 *     through to the regular UNPAID/EXPIRED branch
	 *
	 * Bounded: ~6 mint hits per pending minute per order (at 5s poll + 10s
	 * cache). Zero hits once the marker is cleared.
	 */
	private function resolve_pending_melt( WC_Order $order ): WP_REST_Response|WP_Error|null {
		$pending_quote_id = (string) $order->get_meta( '_cashu_melt_pending_quote_id', true );
		if ( '' === $pending_quote_id ) {
			return null;
		}

		// Stale-marker TTL. A genuinely stuck LN payment will sit in PENDING
		// indefinitely otherwise, and every browser hit pays the 10s-cached
		// mint check (~6 hits/pending minute/order). Past the TTL, drop the
		// marker so the order falls back to UNPAID/EXPIRED.
		$pending_at = absint( $order->get_meta( '_cashu_melt_pending_at', true ) );
		if ( $pending_at > 0 && ( time() - $pending_at ) > self::PENDING_MARKER_MAX_AGE ) {
			Logger::error( 'pending melt marker aged out for order ' . $order->get_id() . ', quote ' . $pending_quote_id );
			// Match MeltReconciler's age-out so the admin gets the same
			// recovery hint whichever path wins the race past the TTL.
			// Without this note, a browser-poll that hits the age-out first
			// would silently drop the marker and leave no audit trail.
			$order->add_order_note(
				sprintf(
					/* translators: %s: melt quote id */
					__( 'Cashu melt left unresolved past 24h. Quote: %s. Check the mint manually if the merchant LN address has received funds.', 'cashu-for-woocommerce' ),
					$pending_quote_id
				)
			);
			$order->delete_meta_data( '_cashu_melt_pending_quote_id' );
			$order->delete_meta_data( '_cashu_melt_pending_at' );
			$order->save();
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
			// Empty response = mint unreachable, rate-limited, or other
			// non-200. Cache longer to avoid hammering a struggling mint;
			// the marker survives so a later probe still finalises the order.
			$ttl = empty( $mint_response ) ? CashuGateway::MELT_STATE_EMPTY_TTL : CashuGateway::MELT_STATE_FRESH_TTL;
			set_transient( $cache_key, $mint_response, $ttl );
		}

		$state = isset( $mint_response['state'] ) ? (string) $mint_response['state'] : '';

		if ( 'PAID' === $state ) {
			$preimage = isset( $mint_response['payment_preimage'] ) && is_string( $mint_response['payment_preimage'] )
				? $mint_response['payment_preimage']
				: '';
			$amount   = isset( $mint_response['amount'] ) ? (string) $mint_response['amount'] : '';

			Logger::debug( 'resolve_pending_melt PENDING->PAID for order ' . $order->get_id() . ', quote ' . $pending_quote_id );
			if ( ! $this->mark_paid( $order, $pending_quote_id, $preimage, $amount ) ) {
				// Couldn't take the pay lock — leave the marker in place so
				// the next poll retries. The mint state cache will short-
				// circuit the mint hit in the meantime.
				return rest_ensure_response(
					array(
						'ok'    => true,
						'state' => 'PENDING',
					)
				);
			}
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

		if ( 'UNPAID' === $state ) {
			// Positive UNPAID from the mint: the proofs were never consumed,
			// they're back with the customer's wallet. Drop the marker so we
			// don't waste mint hits on a dead quote; the order falls through
			// to the regular UNPAID/EXPIRED flow on the next poll. Stamp the
			// last-attempt timestamp so the receipt page can surface a
			// "previous attempt didn't reach the mint" banner instead of
			// silently reverting to the default "Waiting for payment".
			$order->delete_meta_data( '_cashu_melt_pending_quote_id' );
			$order->delete_meta_data( '_cashu_melt_pending_at' );
			$order->update_meta_data( '_cashu_last_payment_attempt_at', time() );
			$order->save();
			delete_transient( $cache_key );
			return null;
		}

		// Empty / unknown — mint unreachable or returned a state we don't
		// recognise. KEEP the marker: PayController and MeltReconciler will
		// keep trying. Surface as PENDING to the polling browser so it shows
		// "settling at mint" rather than dropping the customer back to the
		// cart on a network blip. Don't delete the cache_key transient — we
		// WANT the cached value to expire naturally (10s TTL) so the next
		// probe re-fetches.
		return rest_ensure_response(
			array(
				'ok'    => true,
				'state' => 'PENDING',
			)
		);
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
				if ( ! $this->mark_paid( $order, $quote_id, $preimage, null ) ) {
					// Lock contention — settlement is in flight elsewhere. Tell
					// the browser to keep polling rather than claiming PAID on
					// an order that's still in pending status.
					return rest_ensure_response(
						array(
							'ok'    => true,
							'state' => 'PENDING',
						)
					);
				}
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

		// Pre-stage the pending marker BEFORE the mint probe. Any client
		// reaching this branch has just called wallet.meltProofsBolt11 on
		// the trusted mint — proofs may already be committed and the LN
		// payment in flight. If our probe below fails (network blip) or
		// the customer closes the tab before we reach a state-specific
		// branch, this marker is the only durable signal MeltReconciler
		// can follow to finalise the order. Mirrors PayController's
		// pre-stage pattern (commit 0691181). Idempotent — refreshes the
		// timestamp on every claim attempt; the UNPAID branch below
		// clears it when the mint positively says proofs are unconsumed.
		$order->update_meta_data( '_cashu_melt_pending_quote_id', $quote_id );
		$order->update_meta_data( '_cashu_melt_pending_at', time() );
		$order->save();

		$url = rtrim( $order_mint, '/' ) . '/v1/melt/quote/bolt11/' . rawurlencode( $quote_id );
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
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
			Logger::debug( 'Mint quote state HTTP ' . $code . ' for order ' . $order->get_id() . ', quote ' . $quote_id );
			return new WP_Error( 'cashu_mint_http', 'Mint returned a non 200 response.', array( 'status' => 502 ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['state'] ) ) {
			Logger::debug( 'Mint quote state invalid JSON for order ' . $order->get_id() . ', quote ' . $quote_id );
			return new WP_Error( 'cashu_mint_json', 'Mint returned invalid JSON.', array( 'status' => 502 ) );
		}

		$state           = (string) $data['state'];
		$mint_preimage   = ( isset( $data['payment_preimage'] ) && is_string( $data['payment_preimage'] ) )
			? $data['payment_preimage']
			: '';
		$reported_amount = isset( $data['amount'] ) ? (string) $data['amount'] : '';

		if ( 'PAID' !== $state ) {
			if ( 'UNPAID' === $state ) {
				// Mint says the merchant melt quote was never paid. For the
				// LN leg, that means the customer's mint-quote proofs were
				// never melted to the vendor — recovery is via NUT-09
				// restore on the next reload (which the browser's
				// startMintQuoteWatcher already handles when state is
				// ISSUED). Clear the pre-staged marker (no proofs at risk)
				// and stamp the failed-attempt timestamp so the receipt
				// page surfaces "previous attempt didn't reach the mint".
				$order->delete_meta_data( '_cashu_melt_pending_quote_id' );
				$order->delete_meta_data( '_cashu_melt_pending_at' );
				$order->update_meta_data( '_cashu_last_payment_attempt_at', time() );
				$order->save();
			}
			// PENDING (and empty/unknown): keep the pre-staged marker so
			// MeltReconciler picks it up; nothing more to do here.
			return rest_ensure_response(
				array(
					'ok'    => true,
					'state' => $state,
				)
			);
		}

		// Cross-check the mint's reported preimage against the stored payment_hash
		// before storing it. The settlement is still trustable — the mint has
		// reported PAID via its authoritative state endpoint — but a recorded
		// preimage that doesn't actually hash to the invoice's payment_hash
		// is misleading audit data.
		if ( '' !== $mint_preimage && '' !== $payment_hash && ! Bolt11::preimageMatches( $mint_preimage, $payment_hash ) ) {
			Logger::error( 'claim_melt_quote: mint preimage does not match invoice hash for order ' . $order->get_id() );
			$mint_preimage = '';
		}

		if ( ! $this->mark_paid( $order, $quote_id, $mint_preimage, $reported_amount ) ) {
			return rest_ensure_response(
				array(
					'ok'    => true,
					'state' => 'PENDING',
				)
			);
		}
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

	private function check_confirm_rate_limit( int $order_id ): bool {
		$key   = 'cashu_wc_confirm_attempts_' . $order_id;
		$count = (int) get_transient( $key );
		if ( $count >= self::CONFIRM_RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::CONFIRM_RATE_LIMIT_TTL );
		return true;
	}

	/**
	 * sha256(preimage) == payment_hash. Delegates to Bolt11 so the same
	 * primitive is used in PayController too.
	 */
	private function preimage_matches( string $preimage_hex, string $payment_hash_hex ): bool {
		return Bolt11::preimageMatches( $preimage_hex, $payment_hash_hex );
	}

	/**
	 * Returns true if the order is now (or was already) paid, false if we
	 * couldn't take the lock in a reasonable time. The caller MUST surface
	 * the false case to the browser as a non-PAID state — claiming PAID
	 * + redirecting on an unmarked order leaves the customer staring at
	 * a still-pending order on the thank-you page.
	 */
	private function mark_paid( WC_Order $order, string $quote_id, ?string $preimage, ?string $amount ): bool {
		if ( $order->is_paid() ) {
			return true;
		}

		$order_id = $order->get_id();

		// Share the 'pay' scope with PayController so the cashu-leg and
		// lightning-leg can't both call payment_complete on the same
		// order — that would fire WC's payment-complete actions (emails,
		// stock, etc.) twice. The is_paid() check above guards the
		// in-process case; the lock + re-check below covers cross-process
		// races where each process has its own WC object cache. The wait
		// budget (60s) is sized to outlast PayController's 90s in-flight
		// mint melt only partially — we expect the holder to finish in
		// well under that on a healthy mint, and on contention timeout
		// we'd rather have the caller report pending than block the
		// browser indefinitely. The caller is responsible for re-polling.
		if ( ! OrderLock::acquire( $order_id, 'pay', 30 ) ) {
			Logger::debug( 'mark_paid waiting on pay lock for order ' . $order_id );
			OrderLock::wait_for_release( $order_id, 'pay', 60 );
			$fresh = wc_get_order( $order_id );
			if ( $fresh && $fresh->is_paid() ) {
				return true;
			}
			if ( ! OrderLock::acquire( $order_id, 'pay', 30 ) ) {
				Logger::error( 'mark_paid lock contention timeout for order ' . $order_id );
				return false;
			}
		}

		try {
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh ) {
				return false;
			}
			if ( $fresh->is_paid() ) {
				return true;
			}

			if ( null !== $preimage && '' !== $preimage ) {
				$fresh->update_meta_data( '_cashu_payment_preimage', sanitize_text_field( $preimage ) );
			}
			// Clear all pending-state markers in one place so every settlement
			// path (cryptographic-preimage, mint-probed PAID, reconciler) leaves
			// the order in the same final shape.
			$fresh->delete_meta_data( '_cashu_melt_pending_quote_id' );
			$fresh->delete_meta_data( '_cashu_melt_pending_at' );
			$fresh->delete_meta_data( '_cashu_last_payment_attempt_at' );
			$fresh->payment_complete( $quote_id );

			$this->add_paid_order_note( $fresh, $quote_id, $preimage, $amount );

			// Keep the caller's $order reference in sync.
			$order->read_meta_data( true );
			return true;
		} finally {
			OrderLock::release( $order_id, 'pay' );
		}
	}

	private function add_paid_order_note( WC_Order $order, string $quote_id, ?string $preimage, ?string $amount ): void {
		// Prefer the LN address snapshotted at quote creation; fall back
		// to the current option for legacy orders that pre-date that snapshot.
		$lightning_address = (string) $order->get_meta( '_cashu_invoice_ln_address', true );
		if ( '' === $lightning_address ) {
			$lightning_address = (string) get_option( 'cashu_lightning_address', '' );
		}
		$amount_for_note = ( null !== $amount && '' !== $amount )
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
