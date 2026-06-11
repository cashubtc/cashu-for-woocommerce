<?php

declare(strict_types=1);

namespace Cashu\WC\Admin;

use Cashu\WC\Helpers\Logger;

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
}
