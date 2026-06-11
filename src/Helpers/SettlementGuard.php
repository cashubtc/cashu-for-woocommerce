<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

use WC_Order;

/**
 * One-way "paid once" sentinel shared by the three settlement finalizers
 * (PayController::finalise_paid, ConfirmMeltQuoteController::mark_paid,
 * MeltReconciler::finalise_paid_locked).
 *
 * WC core's payment_complete() accepts orders in `cancelled` and `failed`
 * status (OrderStatus::PAYMENT_COMPLETE_STATUSES). Without this sentinel a
 * customer who settled once and was later refunded out-of-band could replay
 * their stored preimage (or let the mint's PAID quote state be re-probed)
 * against an admin-cancelled order and silently revive it to processing —
 * re-decrementing stock and firing order-paid emails for an order the
 * merchant explicitly killed.
 *
 * The sentinel is strictly better than filtering
 * `woocommerce_valid_order_statuses_for_payment_complete`: a blanket filter
 * would also block the LEGITIMATE first settlement on an order WooCommerce
 * auto-cancelled (hold-stock timeout) while the melt was still in flight at
 * the mint — customer paid, order must complete. The sentinel only exists
 * after a real payment_complete, so first settlements always pass.
 */
final class SettlementGuard {

	public const PAID_ONCE_META    = '_cashu_paid_once';
	private const BLOCK_NOTED_META = '_cashu_replay_block_noted';

	/**
	 * Record that payment_complete has fired once for this order. Called
	 * immediately before payment_complete() so the same save() persists it.
	 * Never overwritten, never deleted — cancel/refund flows leave it alone.
	 */
	public static function mark_paid_once( WC_Order $order ): void {
		if ( '' === (string) $order->get_meta( self::PAID_ONCE_META, true ) ) {
			$order->update_meta_data( self::PAID_ONCE_META, (string) time() );
		}
	}

	/**
	 * True when a settlement signal arrives for an order that already went
	 * through payment_complete once but is no longer in a paid status —
	 * i.e. an admin cancelled/failed/refunded it afterwards. Completing
	 * again would move no new funds (the melt quote is single-use; a PAID
	 * mint state or matching preimage only re-proves the original payment),
	 * so the caller must refuse to fire payment_complete.
	 */
	public static function should_block( WC_Order $order ): bool {
		if ( $order->is_paid() ) {
			return false;
		}
		return '' !== (string) $order->get_meta( self::PAID_ONCE_META, true );
	}

	/**
	 * Log + leave a single order note explaining the refusal, so the admin
	 * can see the replay attempt without the note being spammable (the
	 * claim endpoint allows 30 attempts/hour). Does NOT save() — callers
	 * hold the pay lock and save as part of their own flow.
	 */
	public static function note_blocked( WC_Order $order, string $quote_id ): void {
		Logger::error(
			'Refusing to re-complete order ' . $order->get_id()
			. ' (status ' . $order->get_status() . '): payment_complete already fired once for quote ' . $quote_id
		);
		if ( '' !== (string) $order->get_meta( self::BLOCK_NOTED_META, true ) ) {
			return;
		}
		$order->update_meta_data( self::BLOCK_NOTED_META, (string) time() );
		$order->add_order_note(
			sprintf(
				/* translators: %s: melt quote id */
				__( 'Cashu settlement signal received for quote %s, but this order was already paid once and has since left a paid status. Refusing to automatically re-complete it — review manually if this is unexpected.', 'cashu-for-woocommerce' ),
				$quote_id
			)
		);
	}
}
