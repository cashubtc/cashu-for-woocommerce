<?php
declare(strict_types=1);

namespace Cashu\WC\Admin;

use Cashu\WC\Helpers\CashuPaths;
use WC_Admin_Settings;

final class ValidateGlobalSettings {

	private static bool $hooked = false;

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

		return untrailingslashit( $validated );
	}

	public static function sanitize_lightning_address( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$email = is_email( $value );
		if ( $email ) {
			return strtolower( $email );
		}

		WC_Admin_Settings::add_error(
			__( 'Lightning address must be a valid lightning address (name@domain).', 'cashu-for-woocommerce' )
		);

		return null;
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

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$raw_paths = isset( $_POST['cashu_paths'] )
			? wp_unslash( $_POST['cashu_paths'] )
			: CashuPaths::DEFAULT_PATHS;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

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
