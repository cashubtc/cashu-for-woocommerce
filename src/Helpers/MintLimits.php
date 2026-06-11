<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * Cached view of the amount limits that bound a Lightning checkout: the
 * trusted mint's NUT-04/NUT-05 bolt11/sat min/max (from /v1/info) and the
 * merchant Lightning address's LUD-06 min/maxSendable. Refreshed hourly by
 * cron and at settings save, consumed by the gateway to hide the payment
 * option for out-of-range carts before a customer can hit a dead checkout.
 *
 * Everything here fails open: a missing, stale, or source-mismatched
 * snapshot never hides the gateway — only fresh data that clearly excludes
 * the amount does. Quote-time errors remain the authoritative guard.
 */
final class MintLimits {

	/** Dedicated cron hook (kept separate from the melt reconciler so a
	 * failure in either job can never starve the other's callbacks). */
	public const HOOK = 'cashu_wc_refresh_limits';

	/** Option holding the snapshot. An option, not a transient: "last
	 * known" limits should survive cache eviction between cron ticks. */
	public const OPTION = 'cashu_wc_payment_limits';

	/**
	 * Skip a cron refetch when the block is younger than this. Just under
	 * the hourly cron cadence so a settings-save fetch doesn't cause a
	 * double hit on the next tick.
	 */
	public const REFRESH_THROTTLE_SECS = 55 * MINUTE_IN_SECONDS;

	/**
	 * A block older than this no longer hides the gateway. Six hourly cron
	 * ticks must fail in a row before limits data goes dark — at which
	 * point we'd rather show the option and let quote-time errors speak.
	 */
	public const STALE_AFTER_SECS = 6 * HOUR_IN_SECONDS;

	/**
	 * The customer mint leg pays order total + LN fee_reserve + input-fee
	 * buffer, but fee_reserve isn't known until a quote exists. Mirror the
	 * melt-total construction (1% buffer, small constant) when testing the
	 * NUT-04 max so we don't pass an order the subsequent quote would bounce.
	 */
	private const MINT_LEG_HEADROOM_PCT  = 0.02;
	private const MINT_LEG_HEADROOM_SATS = 4;

	/**
	 * Extract {min,max} sats from the bolt11/sat method entry under
	 * nuts[$nut_key]. Returns null when the method isn't advertised.
	 *
	 * NUT-23 marks min_amount/max_amount optional and its examples include
	 * `"min_amount": 0`, so absent, null and 0 must all mean "no limit" —
	 * only a positive integer is a constraint. (A bare truthiness check
	 * would be fine for min but would turn a malformed `"max_amount": 0`
	 * into "max 0 sats" and block every checkout.)
	 */
	public static function extract_bolt11_sat_range( array $nuts, string $nut_key ): ?array {
		$nut = $nuts[ $nut_key ] ?? null;
		if ( ! is_array( $nut ) || ! empty( $nut['disabled'] ) ) {
			return null;
		}
		$methods = is_array( $nut['methods'] ?? null ) ? $nut['methods'] : array();
		foreach ( $methods as $m ) {
			if (
				is_array( $m )
				&& isset( $m['method'], $m['unit'] )
				&& strtolower( (string) $m['method'] ) === 'bolt11'
				&& strtolower( (string) $m['unit'] ) === 'sat'
			) {
				return array(
					'min' => self::positive_int_or_null( $m['min_amount'] ?? null ),
					'max' => self::positive_int_or_null( $m['max_amount'] ?? null ),
				);
			}
		}
		return null;
	}

	/**
	 * Convert LUD-06 msat sendable bounds to whole sats: min rounds up,
	 * max rounds down (the conservative direction for each). Non-numeric
	 * or non-positive values mean unbounded.
	 */
	public static function lnurl_range_sats( array $metadata ): array {
		$min = null;
		$max = null;
		if ( is_numeric( $metadata['minSendable'] ?? null ) ) {
			$min = self::positive_int_or_null( (int) ceil( ( (float) $metadata['minSendable'] ) / 1000 ) );
		}
		if ( is_numeric( $metadata['maxSendable'] ?? null ) ) {
			// A sub-sat maxSendable can't be represented (floor = 0) and is
			// treated as unbounded rather than "max 0"; save-time validation
			// already rejects max < min.
			$max = self::positive_int_or_null( (int) floor( ( (float) $metadata['maxSendable'] ) / 1000 ) );
		}
		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	/**
	 * Persist the mint's NUT-04/05 bolt11/sat limits from a decoded
	 * /v1/info body. Returns the stored block so save-time callers can
	 * build admin messaging without re-reading (the option write may still
	 * be queued behind WC's batched settings save).
	 */
	public static function store_mint_limits( string $mint_url, array $info_body ): array {
		$nuts       = is_array( $info_body['nuts'] ?? null ) ? $info_body['nuts'] : array();
		$mint_range = self::extract_bolt11_sat_range( $nuts, '4' );
		$melt_range = self::extract_bolt11_sat_range( $nuts, '5' );

		$block = array(
			'url'        => MintClient::normalize_url( $mint_url ),
			'mint_min'   => $mint_range['min'] ?? null,
			'mint_max'   => $mint_range['max'] ?? null,
			'melt_min'   => $melt_range['min'] ?? null,
			'melt_max'   => $melt_range['max'] ?? null,
			'fetched_at' => time(),
		);

		$snapshot         = self::snapshot();
		$snapshot['mint'] = $block;
		update_option( self::OPTION, $snapshot );

		return $block;
	}

	/**
	 * Persist the Lightning address's LUD-06 sendable bounds from decoded
	 * metadata. Returns the stored block (see store_mint_limits()).
	 */
	public static function store_lnurl_limits( string $address, array $metadata ): array {
		$range = self::lnurl_range_sats( $metadata );

		$block = array(
			'address'    => strtolower( trim( $address ) ),
			'min'        => $range['min'],
			'max'        => $range['max'],
			'fetched_at' => time(),
		);

		$snapshot          = self::snapshot();
		$snapshot['lnurl'] = $block;
		update_option( self::OPTION, $snapshot );

		return $block;
	}

	/** Raw stored snapshot; empty array when unset/malformed. */
	public static function snapshot(): array {
		$snapshot = get_option( self::OPTION, array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * The mint block, but only when it's trustworthy for gating checkout:
	 * fetched from the currently configured mint and fresh enough. Null
	 * means "don't judge" — callers must fail open.
	 */
	public static function mint_block(): ?array {
		$block = self::snapshot()['mint'] ?? null;
		if ( ! is_array( $block ) ) {
			return null;
		}
		$current = MintClient::normalize_url( trim( (string) get_option( 'cashu_trusted_mint', '' ) ) );
		if ( '' === $current || MintClient::normalize_url( (string) ( $block['url'] ?? '' ) ) !== $current ) {
			return null;
		}
		if ( self::is_stale( $block ) ) {
			return null;
		}
		return $block;
	}

	/** LUD-06 counterpart of mint_block(); same trust rules, same fail-open contract. */
	public static function lnurl_block(): ?array {
		$block = self::snapshot()['lnurl'] ?? null;
		if ( ! is_array( $block ) ) {
			return null;
		}
		$current = strtolower( trim( (string) get_option( 'cashu_lightning_address', '' ) ) );
		if ( '' === $current || (string) ( $block['address'] ?? '' ) !== $current ) {
			return null;
		}
		if ( self::is_stale( $block ) ) {
			return null;
		}
		return $block;
	}

	/**
	 * Can an order of this many sats clear both legs? True unless fresh
	 * snapshot data clearly says no.
	 *
	 * The merchant melt leg (NUT-05 + LNURL) is checked against the bare
	 * order total — that's the invoice amount. The customer mint leg
	 * (NUT-04) max is checked with fee headroom (see MINT_LEG_HEADROOM_*);
	 * its min is checked against the bare total, since the real mint-leg
	 * amount is always larger and can only help clear a minimum.
	 */
	public static function allows( int $order_total_sats ): bool {
		if ( $order_total_sats <= 0 ) {
			return true;
		}

		$mint = self::mint_block();
		if ( null !== $mint ) {
			if ( ! self::within( $order_total_sats, $mint['melt_min'] ?? null, $mint['melt_max'] ?? null ) ) {
				return false;
			}
			$mint_leg_max = (int) ceil( $order_total_sats * ( 1 + self::MINT_LEG_HEADROOM_PCT ) ) + self::MINT_LEG_HEADROOM_SATS;
			if ( ! self::within( $order_total_sats, $mint['mint_min'] ?? null, null )
				|| ! self::within( $mint_leg_max, null, $mint['mint_max'] ?? null )
			) {
				return false;
			}
		}

		$lnurl = self::lnurl_block();
		if ( null !== $lnurl && ! self::within( $order_total_sats, $lnurl['min'] ?? null, $lnurl['max'] ?? null ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Cron / failure-path entry point: refetch both sources and update the
	 * snapshot. Never throws and never erases data — a failed fetch keeps
	 * the previous block (staleness ages it out of gating naturally).
	 *
	 * @param bool $force Skip the freshness throttle (used when a checkout
	 *                    just failed with a limit error, i.e. the cached
	 *                    limits are demonstrably behind the mint's).
	 */
	public static function refresh( bool $force = false ): void {
		try {
			$snapshot = self::snapshot();

			$mint_url = untrailingslashit( trim( (string) get_option( 'cashu_trusted_mint', '' ) ) );
			if ( '' !== $mint_url && ( $force || self::needs_refetch( $snapshot['mint'] ?? null, 'url', MintClient::normalize_url( $mint_url ) ) ) ) {
				$info = self::fetch_json( $mint_url . '/v1/info' );
				if ( null !== $info ) {
					self::store_mint_limits( $mint_url, $info );
				}
			}

			$address = strtolower( trim( (string) get_option( 'cashu_lightning_address', '' ) ) );
			if ( '' !== $address && false !== strpos( $address, '@' ) && ( $force || self::needs_refetch( $snapshot['lnurl'] ?? null, 'address', $address ) ) ) {
				list( $name, $host ) = explode( '@', $address, 2 );
				$metadata            = self::fetch_json( sprintf( 'https://%s/.well-known/lnurlp/%s', $host, rawurlencode( $name ) ) );
				if ( null !== $metadata ) {
					self::store_lnurl_limits( $address, $metadata );
				}
			}
		} catch ( \Throwable $e ) {
			// Limits refresh is advisory only; it must never break the
			// caller (cron tick or checkout failure path).
			Logger::debug( 'MintLimits refresh failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Human-readable form of a sats range for admin surfaces. Null bounds
	 * render as the unbounded phrasing.
	 */
	public static function format_range( ?int $min, ?int $max ): string {
		if ( null !== $min && null !== $max ) {
			return sprintf(
				/* translators: 1: minimum sats, 2: maximum sats */
				__( '%1$s–%2$s sat', 'cashu-for-woocommerce' ),
				number_format_i18n( $min ),
				number_format_i18n( $max )
			);
		}
		if ( null !== $max ) {
			return sprintf(
				/* translators: %s: maximum sats */
				__( 'up to %s sat', 'cashu-for-woocommerce' ),
				number_format_i18n( $max )
			);
		}
		if ( null !== $min ) {
			return sprintf(
				/* translators: %s: minimum sats */
				__( 'from %s sat', 'cashu-for-woocommerce' ),
				number_format_i18n( $min )
			);
		}
		return __( 'no advertised limits', 'cashu-for-woocommerce' );
	}

	/** True when the block is missing, from a different source, or older than the throttle. */
	private static function needs_refetch( $block, string $source_key, string $source_value ): bool {
		if ( ! is_array( $block ) || (string) ( $block[ $source_key ] ?? '' ) !== $source_value ) {
			return true;
		}
		$fetched_at = absint( $block['fetched_at'] ?? 0 );
		return $fetched_at <= 0 || time() - $fetched_at >= self::REFRESH_THROTTLE_SECS;
	}

	private static function is_stale( array $block ): bool {
		$fetched_at = absint( $block['fetched_at'] ?? 0 );
		return $fetched_at <= 0 || time() - $fetched_at > self::STALE_AFTER_SECS;
	}

	/** GET a JSON endpoint; null on any transport, HTTP, or decode failure. */
	private static function fetch_json( string $url ): ?array {
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			Logger::debug( 'MintLimits fetch failed: ' . $res->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			Logger::debug( 'MintLimits fetch HTTP ' . $code . ' for ' . $url );
			return null;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $json ) ? $json : null;
	}

	private static function within( int $value, ?int $min, ?int $max ): bool {
		if ( null !== $min && $value < $min ) {
			return false;
		}
		if ( null !== $max && $value > $max ) {
			return false;
		}
		return true;
	}

	/** Only positive ints are constraints; 0 / null / absent / junk mean unbounded. */
	private static function positive_int_or_null( $value ): ?int {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 ? $int : null;
	}
}
