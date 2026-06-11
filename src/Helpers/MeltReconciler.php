<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

use WC_Order;

/**
 * Cron-driven sweep that finalises orders left carrying a pending-melt
 * marker (staged by PayController and the claim endpoint). Runs hourly via
 * wp_schedule_event; bounded at MAX_PER_RUN orders per tick and per-order
 * throttled via transient so a stuck mint can't be hammered by repeated
 * cron ticks.
 *
 * The customer-side polling endpoint handles the foreground case (active
 * tab, fast settlement); this is the long-tail consumer for orders where
 * the customer has closed their tab before the mint resolves.
 */
final class MeltReconciler {

	public const HOOK = 'cashu_wc_reconcile_pending_melts';

	/** Hard cap per cron tick. Stops a backlog from fanning out to mint. */
	private const MAX_PER_RUN = 20;

	/** Per-order throttle. One mint probe per order per hour from this path. */
	private const PER_ORDER_THROTTLE_SECS = HOUR_IN_SECONDS;

	/**
	 * Cron entry point. Wired in CashuWCPlugin::run().
	 */
	public static function reconcile_pending(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		// Order by oldest _cashu_melt_pending_at first so a backlog larger
		// than MAX_PER_RUN can't starve older orders — each tick takes the
		// 20 oldest, the next tick takes the next batch, etc.
		// Bounded cron sweep (20/tick) over a sparse marker meta written only
		// when a melt is left in flight at the mint. No alternative lookup
		// path exists; query is intentional and rate-limited per-order.
		// Cancelled/failed are included so a melt that settles AFTER
		// WooCommerce's hold-stock auto-cancel (or a hasty manual cancel)
		// still gets finalised — the customer's funds moved; the order must
		// reflect that. payment_complete accepts cancelled/failed, and the
		// SettlementGuard sentinel in finalise_paid_locked stops this from
		// re-completing an order that was already paid once.
		$orders = wc_get_orders(
			array(
				'status'         => array( 'pending', 'on-hold', 'cancelled', 'failed' ),
				'payment_method' => 'cashu_default',
				'limit'          => self::MAX_PER_RUN,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => '_cashu_melt_pending_at',
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => '_cashu_melt_pending_quote_id',
						'compare' => 'EXISTS',
					),
				),
				'return'         => 'objects',
			)
		);

		if ( ! is_array( $orders ) ) {
			return;
		}

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			self::reconcile_one( $order );
		}
	}

	/**
	 * Probe the mint for one order. Public so tests and the admin
	 * meta-box's "Retry mint probe" button can call it directly.
	 *
	 * Takes the `pay`-scope OrderLock at the top so concurrent PayController /
	 * mark_paid / archive_melt_quote paths can't race our marker mutations and
	 * leave an order in a state nothing knows to reconcile. If another path
	 * holds the lock, skip this order — it'll be picked up on the next tick.
	 *
	 * `$force = true` bypasses the per-order hourly throttle so the admin
	 * retry button can probe immediately. The throttle exists to stop the
	 * cron from hammering a struggling mint; on a manual admin click that
	 * concern doesn't apply (the human is explicitly asking). The pay-lock
	 * and 24h age-out still apply either way.
	 */
	public static function reconcile_one( WC_Order $order, bool $force = false ): void {
		$order_id   = $order->get_id();
		$lock_token = OrderLock::acquire( $order_id, 'pay', 30 );

		if ( null === $lock_token ) {
			Logger::debug( 'MeltReconciler skipping order ' . $order_id . ': pay lock held' );
			return;
		}

		try {
			// Re-read under the lock — the prior holder may have just settled
			// or rotated the quote.
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh ) {
				return;
			}

			if ( $fresh->is_paid() ) {
				$fresh->delete_meta_data( '_cashu_melt_pending_quote_id' );
				$fresh->delete_meta_data( '_cashu_melt_pending_at' );
				$fresh->save();
				return;
			}

			$quote_id = (string) $fresh->get_meta( '_cashu_melt_pending_quote_id', true );
			if ( '' === $quote_id ) {
				return;
			}

			$mint_url = (string) $fresh->get_meta( '_cashu_melt_mint', true );
			if ( '' === $mint_url ) {
				Logger::debug( 'MeltReconciler skipping order ' . $order_id . ': no mint URL' );
				return;
			}

			// Age-out: past TTL, drop marker, write an orphan note for the admin.
			$pending_at = absint( $fresh->get_meta( '_cashu_melt_pending_at', true ) );
			if ( $pending_at > 0 && ( time() - $pending_at ) > DAY_IN_SECONDS ) {
				Logger::error( 'MeltReconciler aging out marker for order ' . $order_id . ', quote ' . $quote_id );
				$fresh->add_order_note(
					sprintf(
						/* translators: %s: melt quote id */
						__( 'Cashu melt left unresolved past 24h. Quote: %s. Check the mint manually if the merchant LN address has received funds.', 'cashu-for-woocommerce' ),
						$quote_id
					)
				);
				$fresh->delete_meta_data( '_cashu_melt_pending_quote_id' );
				$fresh->delete_meta_data( '_cashu_melt_pending_at' );
				$fresh->save();
				return;
			}

			// Per-order throttle: one mint probe per order per hour from cron.
			// Admin "Retry mint probe" bypasses this — see $force note above.
			$throttle_key = 'cashu_wc_recon_' . $order_id;
			if ( ! $force && false !== get_transient( $throttle_key ) ) {
				return;
			}
			set_transient( $throttle_key, '1', self::PER_ORDER_THROTTLE_SECS );

			$state_info = MintClient::melt_quote_state( $mint_url, $quote_id );
			$state      = isset( $state_info['state'] ) ? (string) $state_info['state'] : '';

			if ( 'PAID' === $state ) {
				self::finalise_paid_locked( $fresh, $quote_id, $state_info );
				return;
			}
			if ( 'UNPAID' === $state ) {
				// Mint never consumed the proofs. Drop the marker; proofs are
				// back with the customer's wallet.
				$fresh->delete_meta_data( '_cashu_melt_pending_quote_id' );
				$fresh->delete_meta_data( '_cashu_melt_pending_at' );
				$fresh->save();
				return;
			}
			// PENDING or empty — leave for the next tick.
		} finally {
			OrderLock::release( $order_id, 'pay', $lock_token );
		}
	}

	/**
	 * Finalise a PAID order. Caller MUST already hold the pay-scope
	 * OrderLock for the order — verified by reconcile_one.
	 */
	private static function finalise_paid_locked( WC_Order $fresh, string $quote_id, array $mint_response ): void {
		if ( $fresh->is_paid() ) {
			return;
		}

		// Replay guard: a PAID state on the single-use melt quote can only
		// re-prove a settlement that already completed this order once.
		// Don't revive an order the admin has since cancelled/failed; drop
		// the markers so the cron stops re-probing it.
		if ( SettlementGuard::should_block( $fresh ) ) {
			SettlementGuard::note_blocked( $fresh, $quote_id );
			$fresh->delete_meta_data( '_cashu_melt_pending_quote_id' );
			$fresh->delete_meta_data( '_cashu_melt_pending_at' );
			$fresh->save();
			return;
		}

		SettlementGuard::complete(
			$fresh,
			$quote_id,
			( isset( $mint_response['payment_preimage'] ) && is_string( $mint_response['payment_preimage'] ) )
				? $mint_response['payment_preimage']
				: '',
			isset( $mint_response['amount'] ) ? (string) $mint_response['amount'] : '',
			/* translators: %1$s: BTC Symbol, %2$s: amount, %3$s: Lightning Address, %4$s: Melt Quote ID, %5$s: Payment preimage (truncated) */
			__( "Cashu payment reconciled from mint: %1\$s%2\$s\nSent to: %3\$s\nMelt quote: %4\$s\nPayment preimage: %5\$s", 'cashu-for-woocommerce' )
		);
	}
}
