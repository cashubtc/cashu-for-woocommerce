<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

use WC_Order;

/**
 * The order's settlement window: how long the customer's mint-quote BOLT11
 * stays acceptable, and whether the 15-minute spot window may slide without
 * repricing.
 *
 * One-sided tolerance: the window only ever slides while the merchant would
 * not be underpaid by more than the band (default 1%). A fresh quote LOWER
 * than the standing total (BTC price rose, the old invoice over-covers)
 * always slides.
 */
final class SpotWindow {

	/** Filter for the re-quote tolerance, a fraction (0.01 = 1%). */
	public const TOLERANCE_FILTER = 'cashu_wc_requote_tolerance';

	public const DEFAULT_TOLERANCE = 0.01;

	/** Hard cap on any payable window when the mint's expiry is absent or huge. */
	public const MAX_WINDOW_SECS = DAY_IN_SECONDS;

	/**
	 * Unix time the order's current mint quote stops being payable: the
	 * mint's advertised expiry capped at 24h from quote creation. 0 when
	 * there is no usable window (no quote, or legacy meta with no expiry).
	 */
	public static function payable_until( WC_Order $order ): int {
		if ( '' === (string) $order->get_meta( '_cashu_mint_quote_id', true ) ) {
			return 0;
		}
		$expiry  = absint( $order->get_meta( '_cashu_mint_quote_expiry', true ) );
		$created = absint( $order->get_meta( '_cashu_mint_quote_created', true ) );
		if ( $created > 0 ) {
			$cap = $created + self::MAX_WINDOW_SECS;
			return $expiry > 0 ? min( $expiry, $cap ) : $cap;
		}
		// Legacy order without the creation stamp: trust the mint expiry alone.
		return $expiry;
	}

	/** True while the customer's BOLT11 is still payable at the mint. */
	public static function quote_payable( WC_Order $order ): bool {
		return time() < self::payable_until( $order );
	}

	/**
	 * Slide the spot window without repricing: re-quote the fiat total and
	 * refresh _cashu_spot_time when the standing sats total still covers it
	 * inside the tolerance band. Anchored against _cashu_spot_total (which
	 * never changes while sliding) so drift cannot ratchet one band per
	 * window. Mutates meta only, callers save().
	 */
	public static function maybe_slide( WC_Order $order ): bool {
		$standing = absint( $order->get_meta( '_cashu_spot_total', true ) );
		if ( $standing <= 0 || ! self::quote_payable( $order ) ) {
			return false;
		}
		try {
			$quote = CashuHelper::fiatToSats( (float) $order->get_total(), $order->get_currency() );
		} catch ( \Throwable $e ) {
			Logger::debug( 'Spot slide skipped, price quote failed: ' . $e->getMessage() );
			return false;
		}
		$fresh = absint( $quote['sats'] ?? 0 );
		if ( $fresh <= 0 ) {
			return false;
		}
		$tolerance = (float) apply_filters( self::TOLERANCE_FILTER, self::DEFAULT_TOLERANCE, $order );
		if ( $fresh > (int) floor( $standing * ( 1 + $tolerance ) ) ) {
			return false;
		}
		$order->update_meta_data( '_cashu_spot_time', time() );
		return true;
	}
}
