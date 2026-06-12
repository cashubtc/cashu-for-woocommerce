<?php
declare(strict_types=1);

namespace Cashu\WC\Admin;

use Cashu\WC\Helpers\CashuPaths;
use Cashu\WC\Helpers\MintLimits;
use WC_Admin_Settings;

final class ValidateGlobalSettings {

	private static bool $hooked = false;

	/**
	 * Once-per-request dedupe for the "Lightning address is narrower"
	 * note: when both the mint and the address change in one save, both
	 * probes succeed and would otherwise queue it twice.
	 */
	private static bool $lnurl_narrower_flagged = false;

	/** Test seam: clears the per-request notice dedupe between cases. */
	public static function reset_limits_notice(): void {
		self::$lnurl_narrower_flagged = false;
	}

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		add_filter(
			'woocommerce_admin_settings_sanitize_option_cashu_trusted_mint',
			array( self::class, 'sanitize_trusted_mint' ),
			10
		);

		add_filter(
			'woocommerce_admin_settings_sanitize_option_cashu_lightning_address',
			array( self::class, 'sanitize_lightning_address' ),
			10
		);

		// pre_update_option_cashu_paths fires ONCE with the fully-assembled
		// {unified,cashu,lightning} => 'yes'|'no' array after WC has gathered
		// the three per-sub-key values from the bracket-notation checkboxes.
		// This is where cross-key validation lives — the WC-level sanitize
		// filter fires per sub-key and can't see the whole bitmap at once.
		add_filter(
			'pre_update_option_cashu_paths',
			array( self::class, 'pre_update_paths' ),
			10,
			3
		);

		add_filter(
			'woocommerce_admin_settings_sanitize_option_cashu_default_path',
			array( self::class, 'sanitize_default_path' ),
			10,
			3
		);
	}

	public static function sanitize_trusted_mint( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$validated = wp_http_validate_url( $value );
		if ( ! $validated ) {
			WC_Admin_Settings::add_error( __( 'Trusted Mint URL must be a valid URL.', 'cashu-for-woocommerce' ) );
			return null;
		}

		$parts = wp_parse_url( $validated );
		if ( empty( $parts['scheme'] ) || strtolower( $parts['scheme'] ) !== 'https' ) {
			WC_Admin_Settings::add_error( __( 'Trusted Mint URL must use https.', 'cashu-for-woocommerce' ) );
			return null;
		}

		$url = untrailingslashit( $validated );

		// If the URL is unchanged from what's already stored, skip the NUT-06
		// probe — we don't want to hit the mint on every "Save changes" click
		// when the admin is editing unrelated settings.
		$stored = untrailingslashit( (string) get_option( 'cashu_trusted_mint', '' ) );
		if ( $url === $stored ) {
			return $url;
		}

		$error = self::probe_mint_supports_bolt11_sat( $url );
		if ( null !== $error ) {
			WC_Admin_Settings::add_error( $error );
			return null;
		}

		return $url;
	}

	/**
	 * GET {mint}/v1/info and verify the mint advertises BOLT11/sat support
	 * for both NUT-04 (mint quotes) and NUT-05 (melt quotes). Without both,
	 * Lightning checkout would fail at first customer attempt with the
	 * generic "couldn't reach the mint" error.
	 *
	 * Returns null on success, or a translated error string for the admin.
	 */
	private static function probe_mint_supports_bolt11_sat( string $mint_url ): ?string {
		$response = wp_remote_get(
			$mint_url . '/v1/info',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return sprintf(
				/* translators: %s: mint URL */
				__( 'Could not reach the mint at %s. Check the URL and try again.', 'cashu-for-woocommerce' ),
				esc_html( $mint_url )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return sprintf(
				/* translators: 1: mint URL, 2: HTTP status code */
				__( 'Mint at %1$s returned HTTP %2$d for /v1/info — is this a Cashu mint?', 'cashu-for-woocommerce' ),
				esc_html( $mint_url ),
				$code
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['nuts'] ) || ! is_array( $body['nuts'] ) ) {
			return __( 'Mint info response did not include the NUT capability list — is this a Cashu mint?', 'cashu-for-woocommerce' );
		}

		$required = array(
			array(
				'key'  => '4',
				'name' => 'NUT-04',
				'role' => __( 'customer Lightning → Cashu', 'cashu-for-woocommerce' ),
			),
			array(
				'key'  => '5',
				'name' => 'NUT-05',
				'role' => __( 'vendor Cashu → Lightning', 'cashu-for-woocommerce' ),
			),
		);

		foreach ( $required as $req ) {
			$nut = $body['nuts'][ $req['key'] ] ?? null;

			if ( ! is_array( $nut ) || ! empty( $nut['disabled'] ) ) {
				return sprintf(
					/* translators: 1: NUT identifier (e.g. NUT-04), 2: role description (e.g. customer Lightning → Cashu) */
					__( 'Mint does not advertise %1$s (%2$s) — required for Lightning payments.', 'cashu-for-woocommerce' ),
					$req['name'],
					$req['role']
				);
			}

			$methods = is_array( $nut['methods'] ?? null ) ? $nut['methods'] : array();
			if ( ! self::methods_include_bolt11_sat( $methods ) ) {
				return sprintf(
					/* translators: 1: NUT identifier (e.g. NUT-04), 2: role description (e.g. customer Lightning → Cashu) */
					__( 'Mint does not support BOLT11/sat under %1$s (%2$s) — required for Lightning payments.', 'cashu-for-woocommerce' ),
					$req['name'],
					$req['role']
				);
			}
		}

		// NUT-09 (restore signatures) is the customer's only recovery route
		// when proofs are minted but stranded — a tab that died between mint
		// and melt, or a return on a different device / after a cleared
		// browser, where no local proof snapshot survives. The receipt page
		// re-derives the per-order wallet seed and asks the mint to restore
		// the blinded signatures it already issued. Without NUT-09 that
		// path silently can't work, so a mid-flight failure can cost the
		// customer their funds. Unlike NUT-04/05 this is a settings nut
		// advertised as {"supported": true}, not a methods list.
		if ( ! self::nut_supported_flag( $body['nuts']['9'] ?? null ) ) {
			return __( 'Mint does not advertise NUT-09 (payment recovery) — required so a customer can recover a stranded payment from another device or after clearing their browser. Please choose a mint that supports NUT-09.', 'cashu-for-woocommerce' );
		}

		// Snapshot the advertised bolt11 amount limits while we hold the
		// body (the hourly cron keeps them fresh from here), and set the
		// merchant's expectations up front — a high-ticket store should
		// learn about a 10k-sat melt cap now, not from a customer.
		// Probe results use leveled WP notices, not WC's green messages, so
		// they read apart from "settings saved" instead of blending into it.
		$limits = MintLimits::store_mint_limits( $mint_url, $body );
		Notice::addNotice(
			'info',
			sprintf(
				/* translators: 1: customer pay-in limits (e.g. "100–10,000 sat"), 2: merchant pay-out limits */
				__( 'Mint Lightning limits — customer pay-in: %1$s; pay-out to your Lightning address: %2$s. Checkout hides Cashu for order totals outside these ranges.', 'cashu-for-woocommerce' ),
				MintLimits::format_range( $limits['mint_min'], $limits['mint_max'] ),
				MintLimits::format_range( $limits['melt_min'], $limits['melt_max'] )
			)
		);
		// Echo the mint's own words back to the merchant — a test/joke mint
		// usually says so in its NUT-06 description, and save time is the
		// moment to read it. Mint-authored text inside a kses-rendered
		// notice, hence the esc_html.
		$description = (string) ( $limits['description'] ?? '' );
		if ( '' !== $description ) {
			Notice::addNotice(
				'warning',
				sprintf(
					/* translators: %s: the mint's self-description from its /v1/info */
					__( 'Mint says: “%s”', 'cashu-for-woocommerce' ),
					esc_html( $description )
				)
			);
		}
		self::maybe_flag_lnurl_narrower();

		return null;
	}

	/**
	 * True if a settings-style NUT entry is advertised as supported. The
	 * canonical shape is `{"supported": true}`; some mints shorthand the
	 * whole entry to a bare `true`. Anything else (missing, false, malformed)
	 * is treated as unsupported.
	 *
	 * @param mixed $nut The decoded `nuts[<key>]` value.
	 */
	private static function nut_supported_flag( $nut ): bool {
		if ( true === $nut ) {
			return true;
		}
		return is_array( $nut ) && ! empty( $nut['supported'] );
	}

	private static function methods_include_bolt11_sat( array $methods ): bool {
		foreach ( $methods as $m ) {
			if (
				is_array( $m )
				&& isset( $m['method'], $m['unit'] )
				&& strtolower( (string) $m['method'] ) === 'bolt11'
				&& strtolower( (string) $m['unit'] ) === 'sat'
			) {
				return true;
			}
		}
		return false;
	}

	public static function sanitize_lightning_address( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$email = is_email( $value );
		if ( ! $email ) {
			WC_Admin_Settings::add_error(
				__( 'Lightning address must be a valid lightning address (name@domain).', 'cashu-for-woocommerce' )
			);
			return null;
		}

		$address = strtolower( (string) $email );

		// Skip the LNURL-pay probe when the value is unchanged — we don't
		// want to hit the upstream provider on every "Save changes" click
		// when the admin is editing unrelated settings.
		$stored = strtolower( (string) get_option( 'cashu_lightning_address', '' ) );
		if ( $address === $stored ) {
			return $address;
		}

		$error = self::probe_lightning_address_resolves( $address );
		if ( null !== $error ) {
			WC_Admin_Settings::add_error( $error );
			return null;
		}

		return $address;
	}

	/**
	 * GET the LNURL-pay metadata endpoint for `{name}@{host}` and verify
	 * the response is well-formed LUD-06 metadata advertising payRequest.
	 * Mirrors the NUT-06 probe on trusted_mint: a typo or misconfigured
	 * provider is caught at save time instead of stranding the first
	 * customer who tries to check out.
	 *
	 * Returns null on success, or a translated error string for the admin.
	 */
	private static function probe_lightning_address_resolves( string $address ): ?string {
		// `name@host`. is_email already validated the shape; split is safe.
		$parts = explode( '@', $address, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return __( 'Lightning address must be of the form name@domain.', 'cashu-for-woocommerce' );
		}
		$lnurlp_url = sprintf(
			'https://%s/.well-known/lnurlp/%s',
			$parts[1],
			rawurlencode( $parts[0] )
		);

		$response = wp_remote_get(
			$lnurlp_url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return sprintf(
				/* translators: %s: lightning address */
				__( 'Could not reach the Lightning address provider for %s. Check the address and try again.', 'cashu-for-woocommerce' ),
				esc_html( $address )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return sprintf(
				/* translators: 1: lightning address, 2: HTTP status code */
				__( 'Lightning address %1$s returned HTTP %2$d — is this a valid LNURL-pay endpoint?', 'cashu-for-woocommerce' ),
				esc_html( $address ),
				$code
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return __( 'Lightning address provider returned a malformed response.', 'cashu-for-woocommerce' );
		}

		// LUD-06: tag must be "payRequest", callback must be a usable URL,
		// minSendable/maxSendable must be numeric msat bounds.
		if ( ( $body['tag'] ?? '' ) !== 'payRequest' ) {
			return __( 'Lightning address provider does not advertise a payRequest endpoint.', 'cashu-for-woocommerce' );
		}
		if ( empty( $body['callback'] ) || ! is_string( $body['callback'] ) || ! wp_http_validate_url( $body['callback'] ) ) {
			return __( 'Lightning address provider returned an invalid callback URL.', 'cashu-for-woocommerce' );
		}
		if ( ! isset( $body['minSendable'], $body['maxSendable'] )
			|| ! is_numeric( $body['minSendable'] )
			|| ! is_numeric( $body['maxSendable'] )
			|| (int) $body['maxSendable'] < (int) $body['minSendable']
		) {
			return __( 'Lightning address provider returned invalid send-amount bounds.', 'cashu-for-woocommerce' );
		}

		$limits = MintLimits::store_lnurl_limits( $address, $body );
		Notice::addNotice(
			'info',
			sprintf(
				/* translators: %s: send-amount limits (e.g. "1–500,000 sat") */
				__( 'Lightning address accepts %s. Checkout hides Cashu for order totals outside this range.', 'cashu-for-woocommerce' ),
				MintLimits::format_range( $limits['min'], $limits['max'] )
			)
		);
		self::maybe_flag_lnurl_narrower();

		return null;
	}

	/**
	 * Warn when the Lightning address's sendable range is the binding
	 * constraint, i.e. narrower than the mint's melt range on either end —
	 * that's the surprising cap (merchants expect the mint to be the
	 * limiter, not their wallet provider). Reads the raw snapshot, not the
	 * gated accessors: during a settings save the options aren't written
	 * yet, so source-matching against them would always fail.
	 */
	private static function maybe_flag_lnurl_narrower(): void {
		if ( self::$lnurl_narrower_flagged ) {
			return;
		}

		$snapshot = MintLimits::snapshot();
		$mint     = is_array( $snapshot['mint'] ?? null ) ? $snapshot['mint'] : null;
		$lnurl    = is_array( $snapshot['lnurl'] ?? null ) ? $snapshot['lnurl'] : null;
		if ( null === $mint || null === $lnurl ) {
			return;
		}

		$melt_min  = self::int_or_null( $mint['melt_min'] ?? null );
		$melt_max  = self::int_or_null( $mint['melt_max'] ?? null );
		$lnurl_min = self::int_or_null( $lnurl['min'] ?? null );
		$lnurl_max = self::int_or_null( $lnurl['max'] ?? null );

		$min_binds = null !== $lnurl_min && $lnurl_min > ( $melt_min ?? 1 );
		$max_binds = null !== $lnurl_max && ( null === $melt_max || $lnurl_max < $melt_max );
		if ( ! $min_binds && ! $max_binds ) {
			return;
		}

		self::$lnurl_narrower_flagged = true;
		Notice::addNotice(
			'warning',
			sprintf(
				/* translators: 1: Lightning address limits, 2: mint pay-out limits */
				__( 'Note: your Lightning address (%1$s) is narrower than the mint\'s pay-out range (%2$s) — the Lightning address is the effective limit at checkout.', 'cashu-for-woocommerce' ),
				MintLimits::format_range( $lnurl_min, $lnurl_max ),
				MintLimits::format_range( $melt_min, $melt_max )
			)
		);
	}

	/** Positive ints only; anything else reads as "no limit". */
	private static function int_or_null( $value ): ?int {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 ? $int : null;
	}

	/**
	 * Validate the cashu_paths bitmap (Unified / Cashu / Lightning checkboxes).
	 * Fires via `pre_update_option_cashu_paths` so we get the fully assembled
	 * array, not WC's per-sub-key strings.
	 *
	 * - All three off → abort the write by returning $old_value (or the all-
	 *   enabled default if no prior value existed) and queue an error.
	 * - Unified enabled without both legs → silently coerce Unified off and
	 *   queue an info notice. Derived constraint, not a user mistake.
	 *
	 * Returns a `{unified,cashu,lightning} => 'yes'|'no'` map (NOT bools).
	 * WC's checkbox renderer calls `checked( $value, 'yes' )` which does a
	 * strict string comparison; storing bools would make every checkbox
	 * appear unchecked on the next page load because `(string) true === 'yes'`
	 * is `'1' === 'yes'` → false. Internal callers (`is_available`,
	 * `receipt_page`) read via `CashuPaths::sanitize()` which accepts either
	 * shape, so the yes/no storage doesn't leak past the admin save round-
	 * trip.
	 */
	public static function pre_update_paths( $value, $old_value, $option = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$paths = CashuPaths::sanitize( $value );

		if ( ! CashuPaths::any_enabled( $paths ) ) {
			WC_Admin_Settings::add_error(
				__( 'Please enable at least one payment path (Unified / Cashu / Lightning).', 'cashu-for-woocommerce' )
			);
			if ( is_array( $old_value ) ) {
				return $old_value;
			}
			return array(
				'unified'   => 'yes',
				'cashu'     => 'yes',
				'lightning' => 'yes',
			);
		}

		if ( $paths['unified'] && ( ! $paths['cashu'] || ! $paths['lightning'] ) ) {
			$paths['unified'] = false;
			WC_Admin_Settings::add_message(
				__( 'Unified payments need both Cashu and Lightning enabled — Unified has been turned off.', 'cashu-for-woocommerce' )
			);
		}

		return array(
			'unified'   => $paths['unified'] ? 'yes' : 'no',
			'cashu'     => $paths['cashu'] ? 'yes' : 'no',
			'lightning' => $paths['lightning'] ? 'yes' : 'no',
		);
	}

	/**
	 * Sanitise cashu_default_path. Runs as a per-field WC sanitize filter
	 * (single string value, no brackets, no per-sub-key issue).
	 *
	 * Cannot rely on get_option('cashu_paths') here: WC's save_fields()
	 * accumulates all update_option() calls and batches them AFTER the
	 * sanitize loop, so during this filter the option still holds the OLD
	 * bitmap. Read the about-to-be-saved bitmap from $_POST instead, and
	 * apply the same Unified-needs-both-legs coercion pre_update_paths()
	 * will apply, so the two filters land on a consistent view of what's
	 * being saved.
	 *
	 * Nonce verification is handled by WC's settings handler before this
	 * filter fires, so accessing $_POST here is safe.
	 */
	public static function sanitize_default_path( $value, $option = array(), $raw_value = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$stored = is_string( $value ) ? $value : '';

		// CashuPaths::sanitize() is the sanitizer (it normalises the array
		// shape, casts each entry to bool, and rejects unknown keys), so
		// the raw $_POST value is correctly handled even though the sniff
		// can't see that through the call.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_paths = isset( $_POST['cashu_paths'] )
			? wp_unslash( $_POST['cashu_paths'] )
			: CashuPaths::DEFAULT_PATHS;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$paths = CashuPaths::sanitize( $raw_paths );

		// Mirror the Unified-without-legs coercion that pre_update_paths()
		// will apply during the batch write phase. Without this, a user who
		// disables Cashu/Lightning AND leaves default=Unified would land on
		// an inconsistent DB state (Unified disabled in cashu_paths, but
		// cashu_default_path still 'unified').
		if ( $paths['unified'] && ( ! $paths['cashu'] || ! $paths['lightning'] ) ) {
			$paths['unified'] = false;
		}

		$picked = CashuPaths::default_path( $paths, $stored );

		if ( $picked !== $stored ) {
			WC_Admin_Settings::add_message(
				__( 'Default tab was set to a disabled or unknown path — snapped to the first enabled tab.', 'cashu-for-woocommerce' )
			);
		}

		return $picked;
	}
}
