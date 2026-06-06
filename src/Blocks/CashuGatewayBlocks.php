<?php

namespace Cashu\WC\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class CashuGatewayBlocks extends AbstractPaymentMethodType {
	/**
	 * Payment method name, must match gateway ID.
	 *
	 * @var string
	 */
	protected $name = 'cashu_default';

	/**
	 * Gateway instance.
	 *
	 * @var null|\WC_Payment_Gateway
	 */
	private $gateway;

	public function initialize(): void {
		// Load settings stored by the gateway, option key must match gateway id.
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );

		if ( function_exists( 'WC' ) ) {
			$gateways      = \WC()->payment_gateways()->payment_gateways();
			$this->gateway = $gateways[ $this->name ] ?? null;
		}
	}

	public function is_active(): bool {
		if ( ! $this->gateway instanceof \WC_Payment_Gateway ) {
			return false;
		}

		return $this->gateway->is_available();
	}

	/**
	 * Return supported features so Blocks knows what this gateway can do.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		if ( $this->gateway instanceof \WC_Payment_Gateway ) {
			return $this->gateway->supports;
		}

		return array();
	}

	public function get_payment_method_script_handles(): array {
		$script_url        = CASHU_WC_URL . 'assets/js/frontend/blocks.js';
		$script_asset_path =
		CASHU_WC_PATH . 'assets/js/frontend/blocks.asset.php';
		// blocks.js reads wc.wcSettings, wc.wcBlocksRegistry, wp.htmlEntities
		// and wp.element — declare them so WC Blocks doesn't warn (and so
		// they're guaranteed loaded before our script runs).
		$default_deps = array(
			'wc-settings',
			'wc-blocks-registry',
			'wp-html-entities',
			'wp-element',
		);
		$script_asset = file_exists( $script_asset_path )
		? require $script_asset_path
		: array(
			'dependencies' => $default_deps,
			'version'      => CASHU_WC_VERSION,
		);

		wp_register_script(
			'cashu-gateway-blocks',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'cashu-gateway-blocks',
				'cashu-for-woocommerce',
				CASHU_WC_PATH . 'languages/'
			);
		}

		return array( 'cashu-gateway-blocks' );
	}

	public function get_payment_method_data(): array {
		$title       = $this->get_setting( 'title' );
		$description = $this->get_setting( 'description' );

		return array(
			'title'       => $title,
			'description' => $description,
			'icon'        => CASHU_WC_URL . 'assets/images/cashu-logo-chip.png',
			'supports'    => $this->get_supported_features(),
			'enabled'     => $this->gateway ? $this->gateway->get_option( 'enabled' ) : 'no',
		);
	}
}
