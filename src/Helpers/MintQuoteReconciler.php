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

	/** Persisted rotation cursor for the live cohort (see sweep()'s comment). */
	private const OFFSET_OPTION = 'cashu_wc_mint_sweep_offset';

	/** Keep watching this long past the payable window (stuck HTLC tail). */
	private const GRACE_SECS = DAY_IN_SECONDS;

	/**
	 * Extra window of hourly retries past GRACE_SECS for a quote whose mint
	 * never gives a definitive state. Long enough to survive a multi-day
	 * mint outage; still bounds how long a dead mint can churn the sweep.
	 */
	private const UNRESOLVED_GRACE_SECS = 3 * DAY_IN_SECONDS;

	/**
	 * date_created cutoff separating the "live" and "backlog" cohorts in
	 * sweep(). Longest observed quote TTL (coinos, 7d) + the grace tail +
	 * a day's margin. Scheduling priority only, not a correctness gate:
	 * sweep_one() still ages a live-cohort order out from its own quote
	 * expiry regardless of this cutoff.
	 */
	private const WATCH_HORIZON_SECS = 9 * DAY_IN_SECONDS;

	/** Cron entry point. Wired in CashuWCPlugin::run(). */
	public static function sweep(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$cutoff    = time() - self::WATCH_HORIZON_SECS;
		$base_args = array(
			'status'         => array( 'pending', 'on-hold', 'cancelled', 'failed' ),
			'payment_method' => 'cashu_default',
			'orderby'        => 'date',
			'order'          => 'ASC',
			// Sparse bounded sweep: DONE_META excludes settled and aged
			// orders, so only orders inside their watch window are ever
			// returned.
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
		);

		// Live cohort first, so a backlog of older watched orders (e.g. a
		// burst right after this reconciler shipped) can never starve
		// orders still inside their real watch window.
		//
		// The live population itself can outlive a single tick by days (a
		// watch only leaves via payment, detection, or age-out), so an
		// unrotated oldest-first query would return the SAME MAX_PER_RUN
		// orders on every tick once the cohort passes the cap: order #21
		// onward gets zero probes for up to its whole quote lifetime, and a
		// saturated live cohort also permanently starves the backlog query.
		// Rotating a persisted offset cursor through the cohort trades peak
		// cadence for fairness under load, instead of silently leaving
		// everything past the cap unwatched.
		$offset     = (int) get_option( self::OFFSET_OPTION, 0 );
		$live       = wc_get_orders(
			array_merge(
				$base_args,
				array(
					'date_created' => '>' . $cutoff,
					'limit'        => self::MAX_PER_RUN,
					'offset'       => $offset,
				)
			)
		);
		$live_count = is_array( $live ) ? count( $live ) : 0;

		if ( 0 === $live_count && $offset > 0 ) {
			// The cohort shrank below the stored offset since the last tick
			// (orders left the watch via payment or age-out): retry once
			// from the top rather than wasting this whole tick on a page
			// that can never come back non-empty.
			$offset     = 0;
			$live       = wc_get_orders(
				array_merge(
					$base_args,
					array(
						'date_created' => '>' . $cutoff,
						'limit'        => self::MAX_PER_RUN,
						'offset'       => 0,
					)
				)
			);
			$live_count = is_array( $live ) ? count( $live ) : 0;
		}
		// A full page means more of the cohort is still unseen: advance past
		// it for next tick. Anything shorter means this page reached the end
		// of the cohort, so the next tick starts over from the top.
		update_option( self::OFFSET_OPTION, self::MAX_PER_RUN === $live_count ? $offset + $live_count : 0 );

		self::sweep_orders( $live );

		// A live cohort that is an exact multiple of MAX_PER_RUN fills every
		// page forever (the wraparound retry above included), which would
		// leave $remaining at 0 on every tick and starve the backlog
		// permanently. Guarantee it one slot regardless: the per-tick cap
		// effectively becomes MAX_PER_RUN + 1 under live saturation, one extra
		// probe is immaterial to mint fan-out, and it makes any finite backlog
		// drain unconditionally, since sweep_one() closes a backlog order
		// after a single probe.
		$remaining = max( 1, self::MAX_PER_RUN - $live_count );
		$backlog   = wc_get_orders(
			array_merge(
				$base_args,
				array(
					'date_created' => '<' . $cutoff,
					'limit'        => $remaining,
				)
			)
		);
		self::sweep_orders( $backlog );
	}

	/** Run sweep_one() over a wc_get_orders() result, ignoring non-order entries. */
	private static function sweep_orders( $orders ): void {
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
			if ( '' !== (string) $fresh->get_meta( SettlementGuard::PAID_ONCE_META, true ) ) {
				// Paid once, then cancelled/refunded out-of-band: a later
				// mint read of PAID/ISSUED only re-proves that same dead
				// quote. Never notify the customer on a settlement the
				// merchant explicitly killed.
				$fresh->update_meta_data( self::DONE_META, (string) time() );
				$fresh->save();
				return;
			}
			if ( '' !== (string) $fresh->get_meta( self::DONE_META, true ) ) {
				// Already closed by a prior tick. Guards direct callers
				// (tests, a future admin button) that bypass sweep()'s
				// query-level DONE_META exclusion.
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

			$archived = self::archived_quote_entries( $fresh, $mint );

			// Age out past the invoice's REAL payability plus the stuck-HTLC
			// grace. Raw mint expiry, not the 24h-capped offer window: some
			// mints keep quotes payable for days (coinos observed at 7d) and
			// the watch must cover the invoice's whole payable life. The cap
			// only bounds what we offer (slide, cancel veto), never what we
			// watch. Resolve the CURRENT quote's own deadline through its
			// full fallback chain first (raw expiry, else the spot-window
			// cap, else the spot-time-plus-max-window floor): a 0 expiry is
			// spec-legal and must never be treated as "already expired".
			// THEN take the max with every archived entry's expiry: a
			// trusted-mint change (old quote outlives the new one) or a mint
			// shortening its TTL can leave an archived invoice payable well
			// past the current quote's own deadline. Archived expiries can
			// only ever EXTEND the watch, never shorten it. Decided here but
			// acted on AFTER the probe below, so every order gets at least
			// one final check before its watch closes.
			$until = absint( $fresh->get_meta( '_cashu_mint_quote_expiry', true ) );
			if ( 0 === $until ) {
				$until = SpotWindow::payable_until( $fresh );
			}
			if ( 0 === $until ) {
				$until = absint( $fresh->get_meta( '_cashu_spot_time', true ) ) + SpotWindow::MAX_WINDOW_SECS;
			}
			foreach ( $archived as $entry ) {
				$until = max( $until, $entry['expiry'] );
			}
			$aged_out = time() > $until + self::GRACE_SECS;

			// Group the current quote and every archived entry by the mint
			// that issued them, then probe each mint once. Use the mint the
			// quote was issued at, never the current gateway setting, same
			// rule as ConfirmMeltQuoteController::resolve_pending_melt: after
			// a trusted-mint change an archived quote lives at a different
			// host than $mint, and querying the wrong server would never see
			// its payment.
			$groups = array(
				MintClient::normalize_url( $mint ) => array(
					'url' => $mint,
					'ids' => array( $current ),
				),
			);
			foreach ( $archived as $entry ) {
				$key = MintClient::normalize_url( $entry['mint'] );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'url' => $entry['mint'],
						'ids' => array(),
					);
				}
				$groups[ $key ]['ids'][] = $entry['quote'];
			}
			// array_replace(), not array_merge(): mint quote ids are opaque
			// strings but a purely numeric one becomes an integer array key,
			// and array_merge() renumbers integer keys instead of preserving
			// them, silently dropping that quote's state from the map.
			$states = array();
			foreach ( $groups as $group ) {
				$states = array_replace( $states, MintClient::mint_quote_states( $group['url'], $group['ids'] ) );
			}

			$current_state = (string) ( $states[ $current ] ?? '' );

			// MintClient::mint_quote_states() returns '' for a state it
			// could not verify (transport error, non-200, malformed body, a
			// batch entry the mint omitted), never for a real mint answer.
			// A CLOSE path claims a definitive answer about the ids it
			// covers, so it must never fire on an unverified '' read: that
			// would silently bury a quote a flaky probe just failed to see.
			$archived_authoritative = true;
			foreach ( $archived as $entry ) {
				if ( '' === (string) ( $states[ $entry['quote'] ] ?? '' ) ) {
					$archived_authoritative = false;
					break;
				}
			}
			$all_authoritative = $archived_authoritative && '' !== $current_state;

			$found_this_tick = false;
			if ( 'PAID' === $current_state || 'ISSUED' === $current_state ) {
				self::record_detection( $fresh, $current, $current_state );
				$found_this_tick = true;
			}

			foreach ( $archived as $entry ) {
				$id    = $entry['quote'];
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
					$found_this_tick = true;
				}
			}

			if ( '' !== (string) $fresh->get_meta( self::DETECTED_META, true ) ) {
				if ( self::archived_still_payable( $archived ) ) {
					// Detected, but an archived invoice is still inside its
					// payable window: a customer double-paying that stale,
					// rotated invoice would otherwise never be caught. Keep
					// the order in the watch; record_detection()'s guards
					// already keep this silent (no repeat note or email) on
					// every later tick, and the watch closes once that
					// archived invoice ages past its grace, either via this
					// branch on a later tick or the age-out check below.
					return;
				}
				if ( ! $archived_authoritative ) {
					if ( time() > $until + self::GRACE_SECS + self::UNRESOLVED_GRACE_SECS ) {
						// Mirror of the aged-out unresolved horizon below: a
						// dead mint that never resolves an archived quote's
						// state would otherwise pin this DETECTED order in
						// the sweep forever, permanently occupying the
						// backlog's one guaranteed slot. No note: the
						// detection note already told the admin about the
						// real payment.
						$fresh->update_meta_data( self::DONE_META, (string) time() );
						$fresh->save();
						return;
					}
					// At least one archived quote's state came back unknown
					// this tick: burying it now could hide a payment a flaky
					// probe just missed. Return without side effects; the
					// order stays in the sweep query and the next tick
					// retries.
					return;
				}
				// Detected and no archived invoice is still payable: nothing
				// left to watch for. Completion flips is_paid, which
				// short-circuits above on the next tick.
				$fresh->update_meta_data( self::DONE_META, (string) time() );
				$fresh->save();
				return;
			}

			if ( $aged_out ) {
				// The close claims a definitive answer about every invoice,
				// so it needs every requested state to be authoritative,
				// UNLESS the watch has now run so far past its own deadline
				// that a permanently unreachable mint would otherwise pin
				// the order in the sweep forever.
				$close_now = $all_authoritative
					|| time() > $until + self::GRACE_SECS + self::UNRESOLVED_GRACE_SECS;
				if ( ! $close_now ) {
					// Non-authoritative and still inside the unresolved
					// retry horizon: return without side effects so the
					// next tick retries.
					return;
				}
				// A hit on either meta this tick or a prior one means a
				// payment WAS seen; the "no customer payment" note would
				// contradict the recovery note just written above (or on an
				// earlier tick).
				if ( ! $found_this_tick
					&& '' === (string) $fresh->get_meta( self::DETECTED_META, true )
					&& '' === (string) $fresh->get_meta( self::ARCHIVE_NOTED_META, true )
				) {
					$fresh->add_order_note(
						$all_authoritative
							? __( 'Cashu settlement watch closed: final mint check saw no customer payment inside the watch window.', 'cashu-for-woocommerce' )
							: __( 'Cashu settlement watch closed without a definitive mint response. The mint could not be reached to verify the final quote state; check it manually.', 'cashu-for-woocommerce' )
					);
				}
				$fresh->update_meta_data( self::DONE_META, (string) time() );
				$fresh->save();
			}
		} finally {
			OrderLock::release( $order_id, 'pay', $lock_token );
		}
	}

	/**
	 * Archived quote entries from the order's rotation archive, oldest
	 * first. Each entry keeps the mint it was issued at, so a later
	 * trusted-mint change can never misroute the probe; legacy entries
	 * written before archiving stored a mint fall back to $default_mint.
	 *
	 * Each entry's expiry is resolved to an EFFECTIVE value here, so both
	 * callers (the age-out deadline and archived_still_payable()) can use
	 * it directly without re-deriving it: a 0/absent expiry is spec-legal,
	 * not "already dead", same as everywhere else in the plugin.
	 */
	private static function archived_quote_entries( WC_Order $order, string $default_mint ): array {
		$raw = (string) $order->get_meta( '_cashu_archived_mint_quotes', true );
		if ( '' === $raw ) {
			return array();
		}
		$archive = json_decode( $raw, true );
		if ( ! is_array( $archive ) ) {
			return array();
		}
		// Fallback for archive entries written before the entry carried its
		// own 'created' stamp: the order's CURRENT quote was created at or
		// after that rotation, so capping from it only ever over-watches.
		$order_created = absint( $order->get_meta( '_cashu_mint_quote_created', true ) );

		$entries = array();
		foreach ( $archive as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['quote'] ) ) {
				continue;
			}
			$entry_mint = isset( $entry['mint'] ) ? (string) $entry['mint'] : '';

			// Resolution chain: raw expiry when the mint advertised one;
			// else this entry's own created stamp plus the max window;
			// else the order's current-quote created stamp plus the max
			// window (legacy entry, conservative over-watch); else 0,
			// dead, matching SpotWindow's own legacy fallback.
			$raw_expiry    = absint( $entry['expiry'] ?? 0 );
			$entry_created = absint( $entry['created'] ?? 0 );
			if ( $raw_expiry > 0 ) {
				$expiry = $raw_expiry;
			} elseif ( $entry_created > 0 ) {
				$expiry = $entry_created + SpotWindow::MAX_WINDOW_SECS;
			} elseif ( $order_created > 0 ) {
				$expiry = $order_created + SpotWindow::MAX_WINDOW_SECS;
			} else {
				$expiry = 0;
			}

			$entries[] = array(
				'quote'  => (string) $entry['quote'],
				'mint'   => '' !== $entry_mint ? $entry_mint : $default_mint,
				'expiry' => $expiry,
			);
		}
		return $entries;
	}

	/** True if any archived entry is still inside its payable window (raw expiry plus the grace tail). */
	private static function archived_still_payable( array $entries ): bool {
		$now = time();
		foreach ( $entries as $entry ) {
			if ( $entry['expiry'] > 0 && $now <= $entry['expiry'] + self::GRACE_SECS ) {
				return true;
			}
		}
		return false;
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
