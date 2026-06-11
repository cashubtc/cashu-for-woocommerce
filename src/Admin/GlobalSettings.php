<?php

declare(strict_types=1);

namespace Cashu\WC\Admin;

use Cashu\WC\Helpers\Logger;
use Cashu\WC\Helpers\MintClient;
use Cashu\WC\Helpers\MintLimits;

class GlobalSettings extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = 'cashu_settings';
		$this->label = __( 'Cashu Settings', 'cashu-for-woocommerce' );
		parent::__construct();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// Only on our tab — check the `tab` query arg.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'cashu_settings' !== $tab ) {
			return;
		}
		wp_enqueue_script(
			'cashu-settings-admin',
			CASHU_WC_URL . 'assets/js/backend/cashu-settings.js',
			array( 'jquery' ),
			CASHU_WC_VERSION,
			true
		);
		wp_localize_script(
			'cashu-settings-admin',
			'cashuSettingsL10n',
			array(
				'labels'       => array(
					'unified'   => __( 'Unified (Auto)', 'cashu-for-woocommerce' ),
					'cashu'     => __( 'Cashu', 'cashu-for-woocommerce' ),
					'lightning' => __( 'Lightning', 'cashu-for-woocommerce' ),
				),
				'requiresBoth' => __( 'Requires Cashu + Lightning', 'cashu-for-woocommerce' ),
			)
		);
	}

	public function get_settings_for_default_section(): array {
		return array(
			'title'             => array(
				'id'    => 'cashu_settings_title',
				'title' => __( 'Cashu payments', 'cashu-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __(
					'Accept ecash payments directly to your lightning address via any Cashu mint.',
					'cashu-for-woocommerce'
				),
			),
			'lightning_address' => array(
				'id'          => 'cashu_lightning_address',
				'title'       => __( 'Lightning address', 'cashu-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'you@example.com',
				'desc_tip'    => __(
					'Where melted payments are sent, either a lightning address or LNURL.',
					'cashu-for-woocommerce'
				),
				'desc'        => $this->lnurl_limits_desc(),
				'default'     => '',
			),
			'trusted_mint'      => array(
				'id'          => 'cashu_trusted_mint',
				'title'       => __( 'Trusted Mint URL', 'cashu-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'https://mint.minibits.cash/Bitcoin',
				'desc_tip'    => __(
					'A mint you trust to act as your intermediary.',
					'cashu-for-woocommerce'
				),
				'desc'        => $this->mint_limits_desc(),
				'default'     => 'https://mint.minibits.cash/Bitcoin',
			),
			'section_end_basic' => array(
				'id'   => 'cashu_settings_basic_end',
				'type' => 'sectionend',
			),

			'paths_title'       => array(
				'id'    => 'cashu_paths_title',
				'title' => __( 'Payment paths', 'cashu-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __(
					'Choose which payment options are offered at checkout and which one opens first. Unified (Auto) is a single BIP-321 QR that works with both Cashu and Lightning wallets, and needs both options enabled.',
					'cashu-for-woocommerce'
				),
			),
			// WC has no built-in multi-checkbox field. Render three standalone
			// checkboxes whose IDs share the `cashu_paths[<key>]` shape so PHP
			// POSTs them as an array; WC's save_fields() fires the sanitize filter
			// per sub-key, then assembles them into one `cashu_paths` option write.
			// Cross-key validation hooks `pre_update_option_cashu_paths`,
			// which fires once with the assembled array. `checkboxgroup` start/end
			// visually groups them under one "Show payment paths" label.
			'path_unified'      => array(
				'id'            => 'cashu_paths[unified]',
				'title'         => __( 'Show payment options', 'cashu-for-woocommerce' ),
				'type'          => 'checkbox',
				'desc'          => __( 'Unified (Auto) — single BIP-321 QR. Requires both Cashu and Lightning enabled.', 'cashu-for-woocommerce' ),
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			),
			'path_cashu'        => array(
				'id'            => 'cashu_paths[cashu]',
				'title'         => '',
				'type'          => 'checkbox',
				'desc'          => __( 'Cashu (NUT-18 payment request).', 'cashu-for-woocommerce' ),
				'default'       => 'yes',
				'checkboxgroup' => '',
			),
			'path_lightning'    => array(
				'id'            => 'cashu_paths[lightning]',
				'title'         => '',
				'type'          => 'checkbox',
				'desc'          => __( 'Lightning (BOLT11 invoice).', 'cashu-for-woocommerce' ),
				'default'       => 'yes',
				'checkboxgroup' => 'end',
			),
			'default_path'      => array(
				'id'       => 'cashu_default_path',
				'title'    => __( 'Default tab', 'cashu-for-woocommerce' ),
				'type'     => 'select',
				'options'  => array(
					'unified'   => __( 'Unified (Auto)', 'cashu-for-woocommerce' ),
					'cashu'     => __( 'Cashu', 'cashu-for-woocommerce' ),
					'lightning' => __( 'Lightning', 'cashu-for-woocommerce' ),
				),
				'default'  => 'unified',
				'desc_tip' => __(
					'Which tab opens first at checkout. Snaps to the first enabled tab if the chosen one is disabled.',
					'cashu-for-woocommerce'
				),
			),
			'section_end_paths' => array(
				'id'   => 'cashu_paths_end',
				'type' => 'sectionend',
			),

			'advanced_title'    => array(
				'id'    => 'cashu_advanced_title',
				'title' => __( 'Advanced', 'cashu-for-woocommerce' ),
				'type'  => 'title',
			),
			'debug'             => array(
				'id'      => 'cashu_debug',
				'title'   => __( 'Debug log', 'cashu-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'cashu-for-woocommerce' ),
				'default' => 'no',
				'desc'    => sprintf(
					/* translators: %s is a link to WooCommerce logs page */
					__( 'Log events to the WooCommerce logs, <a href="%s">view logs</a>.', 'cashu-for-woocommerce' ),
					Logger::getLogFileUrl()
				),
			),
			'section_end'       => array(
				'id'   => 'cashu_settings_end',
				'type' => 'sectionend',
			),
		);
	}

	/**
	 * Last-known bolt11 amount limits for the configured mint, shown under
	 * the Trusted Mint field. Empty (so WC renders nothing) until a save or
	 * cron tick has snapshotted them, or when the snapshot belongs to a
	 * different mint than the one currently saved.
	 */
	private function mint_limits_desc(): string {
		$block = MintLimits::snapshot()['mint'] ?? null;
		if ( ! is_array( $block ) ) {
			return '';
		}
		$current = MintClient::normalize_url( trim( (string) get_option( 'cashu_trusted_mint', '' ) ) );
		if ( '' === $current || MintClient::normalize_url( (string) ( $block['url'] ?? '' ) ) !== $current ) {
			return '';
		}
		return sprintf(
			/* translators: 1: customer pay-in limits (e.g. "100–10,000 sat"), 2: merchant pay-out limits, 3: human-readable age (e.g. "5 mins") */
			__( 'Advertised Lightning limits — customer pay-in: %1$s; pay-out: %2$s (checked %3$s ago).', 'cashu-for-woocommerce' ),
			MintLimits::format_range( $this->limit_int( $block, 'mint_min' ), $this->limit_int( $block, 'mint_max' ) ),
			MintLimits::format_range( $this->limit_int( $block, 'melt_min' ), $this->limit_int( $block, 'melt_max' ) ),
			human_time_diff( absint( $block['fetched_at'] ?? 0 ), time() )
		);
	}

	/**
	 * Last-known LUD-06 sendable bounds for the configured Lightning
	 * address, shown under its field. Same visibility rules as
	 * mint_limits_desc().
	 */
	private function lnurl_limits_desc(): string {
		$block = MintLimits::snapshot()['lnurl'] ?? null;
		if ( ! is_array( $block ) ) {
			return '';
		}
		$current = strtolower( trim( (string) get_option( 'cashu_lightning_address', '' ) ) );
		if ( '' === $current || (string) ( $block['address'] ?? '' ) !== $current ) {
			return '';
		}
		return sprintf(
			/* translators: 1: send-amount limits (e.g. "1–500,000 sat"), 2: human-readable age (e.g. "5 mins") */
			__( 'Accepts %1$s (checked %2$s ago).', 'cashu-for-woocommerce' ),
			MintLimits::format_range( $this->limit_int( $block, 'min' ), $this->limit_int( $block, 'max' ) ),
			human_time_diff( absint( $block['fetched_at'] ?? 0 ), time() )
		);
	}

	/** Positive ints only; anything else reads as "no limit". */
	private function limit_int( array $block, string $key ): ?int {
		$value = $block[ $key ] ?? null;
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 ? $int : null;
	}
}
