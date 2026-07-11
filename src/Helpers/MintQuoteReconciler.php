<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

use WC_Order;

/**
 * Hourly sweep that watches customer mint quotes for settlements arriving
 * after the browser stopped watching. Detection only: it never mints and
 * never completes an order. On PAID/ISSUED it marks the order, writes an
 * admin note, and emails the customer a pay-page link; the page-load
 * recovery does the minting and completion.
 *
 * Same discipline as MeltReconciler: bounded per tick, pay-lock per order,
 * sparse query via an exclusion marker so settled/aged orders drop out.
 */
final class MintQuoteReconciler {

	public const HOOK = 'cashu_wc_reconcile_mint_quotes';

	/**
	 * Set once when the mint first reports the customer settlement. Read
	 * by the cancel veto, the pay-page status gate, and the admin meta box.
	 */
	public const DETECTED_META = '_cashu_mint_paid_detected_at';

	/** Sweep exclusion marker: nothing left to watch on this order. */
	public const DONE_META = '_cashu_mint_sweep_done';

	private const NOTIFIED_META      = '_cashu_late_paid_notified';
	private const ARCHIVE_NOTED_META = '_cashu_archived_paid_noted';

	/** Hard cap per cron tick. Stops a backlog from fanning out to mint. */
	private const MAX_PER_RUN = 20;

	/** Keep watching this long past the payable window (stuck HTLC tail). */
	private const GRACE_SECS = DAY_IN_SECONDS;

	/** Cron entry point. Wired in CashuWCPlugin::run(). */
	public static function sweep(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		// Sparse bounded sweep: DONE_META excludes settled and aged orders,
		// so only orders inside their watch window are ever returned.
		$orders = wc_get_orders(
			array(
				'status'         => array( 'pending', 'on-hold', 'cancelled', 'failed' ),
				'payment_method' => 'cashu_default',
				'limit'          => self::MAX_PER_RUN,
				'orderby'        => 'date',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => '_cashu_mint_quote_id',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::DONE_META,
						'compare' => 'NOT EXISTS',
					),
				),
				'return'         => 'objects',
			)
		);
		if ( ! is_array( $orders ) ) {
			return;
		}
		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				self::sweep_one( $order );
			}
		}
	}

	/**
	 * Probe one order's current + archived mint quotes in a single batch.
	 * Public so tests (and a future admin button) can call it directly.
	 */
	public static function sweep_one( WC_Order $order ): void {
		$order_id   = $order->get_id();
		$lock_token = OrderLock::acquire( $order_id, 'pay', 30 );
		if ( null === $lock_token ) {
			Logger::debug( 'MintQuoteReconciler skipping order ' . $order_id . ': pay lock held' );
			return;
		}
		try {
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh ) {
				return;
			}
			if ( $fresh->is_paid() ) {
				$fresh->update_meta_data( self::DONE_META, (string) time() );
				$fresh->save();
				return;
			}

			$current = (string) $fresh->get_meta( '_cashu_mint_quote_id', true );
			$mint    = (string) $fresh->get_meta( '_cashu_mint_quote_mint', true );
			if ( '' === $mint ) {
				// Legacy orders stored only the melt-side mint; both quotes
				// are always issued at the same trusted mint.
				$mint = (string) $fresh->get_meta( '_cashu_melt_mint', true );
			}
			if ( '' === $current || '' === $mint ) {
				$fresh->update_meta_data( self::DONE_META, (string) time() );
				$fresh->save();
				return;
			}

			// Age out past the invoice's REAL payability plus the stuck-HTLC
			// grace. Raw mint expiry, not the 24h-capped offer window: some
			// mints keep quotes payable for days (coinos observed at 7d) and
			// the watch must cover the invoice's whole payable life. The cap
			// only bounds what we offer (slide, cancel veto), never what we
			// watch.
			$until = absint( $fresh->get_meta( '_cashu_mint_quote_expiry', true ) );
			if ( 0 === $until ) {
				$until = SpotWindow::payable_until( $fresh );
			}
			if ( 0 === $until ) {
				$until = absint( $fresh->get_meta( '_cashu_spot_time', true ) ) + SpotWindow::MAX_WINDOW_SECS;
			}
			if ( time() > $until + self::GRACE_SECS ) {
				if ( '' === (string) $fresh->get_meta( self::DETECTED_META, true ) ) {
					$fresh->add_order_note(
						__( 'Cashu settlement watch closed: no customer payment was seen at the mint inside the watch window.', 'cashu-for-woocommerce' )
					);
				}
				$fresh->update_meta_data( self::DONE_META, (string) time() );
				$fresh->save();
				return;
			}

			$archived_ids = self::archived_quote_ids( $fresh );
			$states       = MintClient::mint_quote_states( $mint, array_merge( array( $current ), $archived_ids ) );

			$current_state = (string) ( $states[ $current ] ?? '' );
			if ( 'PAID' === $current_state || 'ISSUED' === $current_state ) {
				self::record_detection( $fresh, $current, $current_state );
			}

			foreach ( $archived_ids as $id ) {
				$state = (string) ( $states[ $id ] ?? '' );
				if ( ( 'PAID' === $state || 'ISSUED' === $state )
					&& '' === (string) $fresh->get_meta( self::ARCHIVE_NOTED_META, true )
				) {
					// Save the guard before the note, same reasoning as
					// record_detection(): add_order_note() persists
					// immediately, so a crash after save but before the note
					// costs at most that note, never a duplicate.
					$fresh->update_meta_data( self::ARCHIVE_NOTED_META, (string) time() );
					$fresh->save();
					$fresh->add_order_note(
						sprintf(
							/* translators: %1$s: archived mint quote id, %2$s: state reported by the mint */
							__( 'Cashu mint reports ARCHIVED quote %1$s as %2$s. The customer paid a rotated invoice; recover manually via the archived quotes list in the Cashu meta box.', 'cashu-for-woocommerce' ),
							$id,
							$state
						)
					);
				}
			}
		} finally {
			OrderLock::release( $order_id, 'pay', $lock_token );
		}
	}

	/** Archived quote ids from the order's rotation archive, oldest first. */
	private static function archived_quote_ids( WC_Order $order ): array {
		$raw = (string) $order->get_meta( '_cashu_archived_mint_quotes', true );
		if ( '' === $raw ) {
			return array();
		}
		$archive = json_decode( $raw, true );
		if ( ! is_array( $archive ) ) {
			return array();
		}
		$ids = array();
		foreach ( $archive as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['quote'] ) ) {
				$ids[] = (string) $entry['quote'];
			}
		}
		return $ids;
	}

	/** Mark the detection once, note once, email the customer once. */
	private static function record_detection( WC_Order $order, string $quote_id, string $state ): void {
		$need_admin_note = '' === (string) $order->get_meta( self::DETECTED_META, true );
		$need_email      = '' === (string) $order->get_meta( self::NOTIFIED_META, true );
		if ( ! $need_admin_note && ! $need_email ) {
			return;
		}

		// Persist the guard metas before writing either note. add_order_note()
		// persists immediately and durably (the customer one also sends an
		// email), while this method's own changes only reach the database on
		// save(). Saving first keeps delivery at-most-once: a crash after
		// save but before the note loses that note (recoverable, the
		// detection marker still surfaces in the admin meta box) but never
		// re-sends a customer email.
		if ( $need_admin_note ) {
			$order->update_meta_data( self::DETECTED_META, (string) time() );
		}
		if ( $need_email ) {
			$order->update_meta_data( self::NOTIFIED_META, (string) time() );
		}
		$order->save();

		if ( $need_admin_note ) {
			$order->add_order_note(
				sprintf(
					/* translators: %1$s: mint quote id, %2$s: state reported by the mint */
					__( 'Cashu mint reports the customer payment (quote %1$s) as %2$s. Open the customer payment page to complete this order.', 'cashu-for-woocommerce' ),
					$quote_id,
					$state
				)
			);
		}
		if ( $need_email ) {
			// Customer note: WooCommerce emails these to the billing
			// address when the "Customer note" email is enabled (default).
			$order->add_order_note(
				sprintf(
					/* translators: %s: order payment URL */
					__( 'Good news, your payment has arrived. Please visit %s to confirm your order.', 'cashu-for-woocommerce' ),
					$order->get_checkout_payment_url()
				),
				1
			);
		}
	}
}
