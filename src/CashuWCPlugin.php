<?php

namespace Cashu\WC;

use Cashu\WC\Admin\GlobalSettings;
use Cashu\WC\Admin\Notice;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Helpers\CashuHelper;
use Cashu\WC\Helpers\ConfirmMeltQuoteController;
use Cashu\WC\Helpers\Logger;
use Cashu\WC\Helpers\MeltReconciler;
use Cashu\WC\Helpers\PayController;

final class CashuWCPlugin {

	private static ?self $instance = null;

	/**
	 * Singleton instance
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all hooks, this is the only place that adds actions and filters.
	 */
	public function run(): void {
		// One-time settings migration. Cheap (one get_option) on every load
		// after first run; runs at most once per install.
		self::maybeMigrateSettings();

		// WooCommerce integration.
		add_filter( 'woocommerce_payment_gateways', array( self::class, 'initPaymentGateways' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declareWooCompat' ) );

		// Thank you page for cashu_default gateway.
		add_action( 'woocommerce_thankyou_cashu_default', array( self::class, 'orderStatusThankYouPage' ), 10, 1 );

		// Order display extras.
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'addCashuOrderItemTotals' ), 20, 2 );

		// Veto WooCommerce's hold-stock auto-cancel while a melt is mid-flight
		// at the mint. Proofs may already be committed and the LN payment
		// routing; cancelling here would race the settlement.
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::class, 'preventCancelDuringSettlement' ), 10, 2 );

		// Settings page.
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'registerSettingsPage' ) );

		// Blocks support.
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'blocksSupport' ) );

		// REST Routes
		add_action(
			'rest_api_init',
			function (): void {
				( new ConfirmMeltQuoteController() )->register_routes();
				( new PayController() )->register_routes();
			}
		);

		// Cron: scan for orders with a pending-melt marker and try to finalise
		// them via the mint's authoritative state. Scheduled on plugin activation.
		add_action( MeltReconciler::HOOK, array( MeltReconciler::class, 'reconcile_pending' ) );

		// Defensive schedule: if the activation hook didn't fire (e.g. plugin
		// updated via SVN without a real activation), schedule on the next init.
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( MeltReconciler::HOOK ) ) {
					wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', MeltReconciler::HOOK );
				}
			}
		);

		// Admin scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminScripts' ) );

		// Admin AJAX.
		add_action( 'wp_ajax_cashu_notifications', array( $this, 'processAjaxNotification' ) );

		// Plugin list action links.
		add_filter( 'plugin_action_links_' . CASHU_WC_BASE, array( $this, 'addPluginActionLinks' ) );

		// Admin only items.
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'registerAdminNotices' ) );
			\Cashu\WC\Admin\ValidateGlobalSettings::init();
			\Cashu\WC\Admin\OrderMetaBox::register();
		}
	}

	public static function initPaymentGateways( array $gateways ): array {
		$gateways[] = CashuGateway::class;
		return $gateways;
	}

	/**
	 * One-time migration that collapses the dual enable toggles.
	 *
	 * Pre-migration the plugin had `cashu_enabled` on the Cashu Settings tab
	 * AND the gateway's own `enabled` option, both required for is_available().
	 * We now read only the gateway enable. An install that had cashu_enabled=yes
	 * but the gateway-enable=no would silently lose checkout at upgrade unless
	 * we flip the gateway on for them.
	 *
	 * Public so tests can call it without booting the full plugin singleton.
	 */
	public static function maybeMigrateSettings(): void {
		if ( 'yes' === get_option( 'cashu_settings_migrated', '' ) ) {
			return;
		}
		if ( 'yes' === get_option( 'cashu_enabled', 'no' ) ) {
			$gw = get_option( 'woocommerce_cashu_default_settings', array() );
			if ( ! is_array( $gw ) ) {
				$gw = array();
			}
			if ( ( $gw['enabled'] ?? 'no' ) !== 'yes' ) {
				$gw['enabled'] = 'yes';
				update_option( 'woocommerce_cashu_default_settings', $gw );
			}
		}
		delete_option( 'cashu_enabled' );
		update_option( 'cashu_settings_migrated', 'yes' );
	}

	/**
	 * `woocommerce_cancel_unpaid_order` filter. WC's hold-stock sweep
	 * cancels unpaid pending orders after `woocommerce_hold_stock_minutes`.
	 * A cashu order with a pending-melt marker has proofs possibly committed
	 * at the mint and an LN payment possibly routing — auto-cancelling it
	 * races the settlement (the reconciler would then have to revive the
	 * cancelled order after the fact). Leave those orders alone; the marker
	 * ages out after 24h and the order becomes cancellable again.
	 *
	 * Public static so it can be unit-tested without booting the singleton.
	 */
	public static function preventCancelDuringSettlement( $should_cancel, $order ) {
		if ( ! $should_cancel || ! $order instanceof \WC_Order ) {
			return $should_cancel;
		}
		if ( 'cashu_default' !== $order->get_payment_method() ) {
			return $should_cancel;
		}
		if ( '' !== (string) $order->get_meta( '_cashu_melt_pending_quote_id', true ) ) {
			return false;
		}
		return $should_cancel;
	}

	public function registerSettingsPage( array $pages ): array {
		if ( ! is_admin() ) {
			return $pages;
		}
		$pages[] = new GlobalSettings();
		return $pages;
	}

	public function declareWooCompat(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				CASHU_WC_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				CASHU_WC_FILE,
				true
			);
		}
	}

	public static function blocksSupport(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ): void {
				$registry->register( new \Cashu\WC\Blocks\CashuGatewayBlocks() );
			}
		);
	}

	public function enqueueAdminScripts(): void {
		// Only the audience that can act on the review notice (admins /
		// shop managers) needs the dismiss-nonce — leaking it to every
		// subscriber visiting wp-admin/profile.php would let them write
		// the cashu_review_dismissed_forever option through the AJAX
		// handler below. Cheap belt-and-braces on top of the capability
		// check in processAjaxNotification().
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_enqueue_script(
			'cashu-notifications',
			CASHU_WC_URL . 'assets/js/backend/notifications.js',
			array( 'jquery' ),
			CASHU_WC_VERSION,
			true
		);
		wp_localize_script(
			'cashu-notifications',
			'cashuNotifications',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'cashu-notifications-nonce' ),
			)
		);
	}

	public function processAjaxNotification(): void {
		if ( ! check_ajax_referer( 'cashu-notifications-nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'cashu-for-woocommerce' ) ), 401 );
		}

		// Nonce alone is not enough: any logged-in user (subscriber,
		// customer) can pull the nonce from their own admin page DOM, and
		// the handler ultimately writes a site-wide option. Require the
		// same capability that gates seeing the notice in the first place.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'cashu-for-woocommerce' ) ), 403 );
		}

		// Nonce already verified above via check_ajax_referer().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$dismiss_forever_raw = isset( $_POST['dismiss_forever'] ) ? sanitize_text_field( wp_unslash( $_POST['dismiss_forever'] ) ) : '0';
		$dismissForever      = \filter_var( $dismiss_forever_raw, \FILTER_VALIDATE_BOOL );

		if ( $dismissForever ) {
			update_option( 'cashu_review_dismissed_forever', true );
		} else {
			set_transient( 'cashu_review_dismissed', true, DAY_IN_SECONDS * 30 );
		}

		wp_send_json_success();
	}

	public function registerAdminNotices(): void {
		$this->dependenciesNotification();
		$this->notConfiguredNotification();
		$this->submitReviewNotification();
	}

	private function dependenciesNotification(): void {
		if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
			Notice::addNotice(
				'error',
				sprintf(
					/* translators: 1: PHP Version string. */
					__( 'Your PHP version is %s but Cashu Payment plugin requires version 8.3+.', 'cashu-for-woocommerce' ),
					PHP_VERSION
				)
			);
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			Notice::addNotice(
				'error',
				__( 'WooCommerce does not appear to be installed. Make sure you do before you activate Cashu Payment Gateway.', 'cashu-for-woocommerce' )
			);
		}

		if ( ! function_exists( 'curl_init' ) ) {
			Notice::addNotice(
				'error',
				__( 'The PHP cURL extension is not installed. Make sure it is available otherwise this plugin will not work.', 'cashu-for-woocommerce' )
			);
		}
	}

	private function notConfiguredNotification(): void {
		if ( ! CashuHelper::getConfig() ) {
			$message = sprintf(
				/* translators: 1: opening <a> tag to settings page 2: closing </a> tag */
				__( 'Plugin not configured yet, please %1$sconfigure the plugin here%2$s', 'cashu-for-woocommerce' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=cashu_settings' ) ) . '">',
				'</a>'
			);

			Notice::addNotice( 'error', $message );
		}
	}

	private function submitReviewNotification(): void {
		if ( get_option( 'cashu_review_dismissed_forever' ) || get_transient( 'cashu_review_dismissed' ) ) {
			return;
		}

		// First-run delay: on the first admin pageload after install/upgrade,
		// record the earliest time we're allowed to nag. Reuses the same
		// 30-day window as "Remind me later" so users only model one concept.
		$earliest_show = (int) get_option( 'cashu_review_earliest_show', 0 );
		if ( 0 === $earliest_show ) {
			update_option( 'cashu_review_earliest_show', time() + ( DAY_IN_SECONDS * 30 ) );
			return;
		}
		if ( time() < $earliest_show ) {
			return;
		}

		$reviewMessage = sprintf(
			/* translators: 1: opening <a> tag to the WordPress.org review page, 2: closing </a> tag, 3: opening <button> tag for "remind me later", 4: closing </button> tag, 5: opening <button> tag for "stop reminding forever", 6: closing </button> tag. Do not translate the HTML tags, keep the placeholder numbers. */
			__( 'Thank you for using Cashu for WooCommerce! If you like the plugin, we would love if you %1$sleave us a review%2$s. %3$sRemind me later%4$s %5$sStop reminding me forever%6$s', 'cashu-for-woocommerce' ),
			'<a href="https://wordpress.org/support/plugin/cashu-for-woocommerce/reviews/#new-post" target="_blank" rel="noopener noreferrer">',
			'</a>',
			'<button class="cashu-review-dismiss" type="button">',
			'</button>',
			'<button class="cashu-review-dismiss-forever" type="button">',
			'</button>'
		);

		Notice::addNotice( 'info', $reviewMessage, false, 'cashu-review-notice' );
	}

	public static function orderStatusThankYouPage( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$status = $order->get_status();

		switch ( $status ) {
			case 'pending':
				$statusDesc = __( 'Waiting payment', 'cashu-for-woocommerce' );
				break;
			case 'on-hold':
				$statusDesc = __( 'Waiting for payment settlement', 'cashu-for-woocommerce' );
				break;
			case 'processing':
				$statusDesc = __( 'Payment settled', 'cashu-for-woocommerce' );
				break;
			case 'completed':
				$statusDesc = __( 'Order completed', 'cashu-for-woocommerce' );
				break;
			case 'failed':
			case 'cancelled':
				$statusDesc = __( 'Payment failed', 'cashu-for-woocommerce' );
				break;
			default:
				$statusDesc = ucfirst( $status );
				break;
		}

		echo '<section class="woocommerce-order-payment-status">';
		echo '<h2 class="woocommerce-order-payment-status-title">' . esc_html__( 'Order Status', 'cashu-for-woocommerce' ) . '</h2>';
		echo '<p><strong>' . esc_html( $statusDesc ) . '</strong></p>';
		echo '</section>';
	}

	public function addCashuOrderItemTotals( array $totals, \WC_Order $order ): array {
		// Only show for our gateway id
		if ( $order->get_payment_method() !== 'cashu_default' ) {
			return $totals;
		}

		$sats = (int) $order->get_meta( '_cashu_melt_total' );
		if ( $sats <= 0 ) {
			return $totals;
		}

		if ( ! $order->is_paid() ) {
			return $totals;
		}

		$totals['cashu_expected_amount'] = array(
			'label' => __( 'Cashu Amount', 'cashu-for-woocommerce' ),
			'value' => esc_html( CASHU_WC_BIP177_SYMBOL . number_format_i18n( $sats ) ),
		);

		return $totals;
	}

	public function addPluginActionLinks( array $links ): array {
		$settings_url = esc_url(
			add_query_arg(
				array(
					'page' => 'wc-settings',
					'tab'  => 'cashu_settings',
				),
				admin_url( 'admin.php' )
			)
		);

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			$settings_url,
			esc_html__( 'Settings', 'cashu-for-woocommerce' )
		);

		$logs_link = '';
		if ( class_exists( Logger::class ) && method_exists( Logger::class, 'getLogFileUrl' ) ) {
			$logs_link = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( Logger::getLogFileUrl() ),
				esc_html__( 'Debug log', 'cashu-for-woocommerce' )
			);
		}

		$docs_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://cashu.space' ),
			esc_html__( 'Docs', 'cashu-for-woocommerce' )
		);

		$prepend = array_filter( array( $settings_link, $logs_link, $docs_link ) );
		return array_merge( $prepend, $links );
	}
}
