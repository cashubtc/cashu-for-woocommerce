<?php

declare(strict_types=1);

namespace Cashu\WC\Gateway;

use Automattic\WooCommerce\Enums\OrderStatus;
use Cashu\WC\Helpers\AmountLimitException;
use Cashu\WC\Helpers\Bolt11;
use Cashu\WC\Helpers\CashuHelper;
use Cashu\WC\Helpers\CashuPaths;
use Cashu\WC\Helpers\Logger;
use Cashu\WC\Helpers\LightningAddress;
use Cashu\WC\Helpers\MintClient;
use Cashu\WC\Helpers\MintLimits;
use Cashu\WC\Helpers\OrderLock;
use Cashu\WC\Helpers\PayController;
use Cashu\WC\Helpers\SpotWindow;
use WC_Order;

class CashuGateway extends \WC_Payment_Gateway {

	public const QUOTE_EXPIRY_SECS = 900;  // 15 mins

	/**
	 * Trusted Mint.
	 * @var string
	 */
	protected $trusted_mint = '';

	/**
	 * Vendor Lightning Address.
	 * @var string
	 */
	protected $ln_address = '';

	/**
	 * Once-per-request render guards. A checkout page that carries two
	 * checkout renderers — e.g. the legacy [woocommerce_checkout]
	 * shortcode left in alongside the checkout block after a migration —
	 * runs the order-pay/thank-you templates twice, firing our render
	 * hooks twice on one request (seen live during testing). A second
	 * render is never valid (duplicate element ids; the JS binds the
	 * first root only), so dedupe defensively. The duplicate fire hits
	 * the same gateway instance, so instance flags suffice — and they
	 * reset naturally per request / per test.
	 *
	 * @var bool
	 */
	private $receipt_rendered = false;

	/**
	 * @var bool
	 */
	private $change_rendered = false;

	public function __construct() {
		$this->id = 'cashu_default';
		// Merchant-facing icon (WC admin Payments overview reads $this->icon
		// directly). Customer-facing icon is swapped in get_icon() below.
		$this->icon               = CASHU_WC_URL . 'assets/images/cashu-logo-chip.png';
		$this->method_title       = __( 'Cashu Bitcoin', 'cashu-for-woocommerce' );
		$this->method_description = __(
			'Accept Cashu ecash and Lightning payments, automatically melted to your Bitcoin lightning address.',
			'cashu-for-woocommerce'
		);
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->init_form_fields();

		$this->title        = $this->get_option( 'title', 'Bitcoin' );
		$this->description  = $this->get_option(
			'description',
			__( 'Make a private Bitcoin payment via Lightning or Cashu ecash.', 'cashu-for-woocommerce' )
		);
		$this->enabled      = $this->get_option( 'enabled' );
		$this->trusted_mint = (string) get_option( 'cashu_trusted_mint', '' );
		$this->ln_address   = (string) get_option( 'cashu_lightning_address', '' );

		// Load / save settings
		$this->init_settings();
		\add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function (): void {
				// Actions expect void return
				$this->process_admin_options();
			}
		);

		// Enqueue gateway scripts / pages
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ), 20 );
		add_action( 'woocommerce_after_my_account', array( $this, 'render_change_section' ), 20 );
	}

	/**
	 * Gateway specific form fields only. Lightning settings live on the Cashu tab.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'title'       => array(
				'title'   => __( 'Title', 'cashu-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Bitcoin', 'cashu-for-woocommerce' ),
			),
			'description' => array(
				'title'   => __( 'Checkout instructions', 'cashu-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __(
					'Make a private Bitcoin payment via Lightning or Cashu ecash.',
					'cashu-for-woocommerce'
				),
			),
			'enabled'     => array(
				'title'       => __( 'Enable/Disable', 'cashu-for-woocommerce' ),
				'label'       => __( 'Enable Cashu Gateway', 'cashu-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
		);
	}

	/**
	 * Customer-facing checkout icon. The Bitcoin mark is built here directly
	 * rather than via $this->icon so the admin Payments overview (which reads
	 * the raw $this->icon property) can keep showing the Cashu chip.
	 *
	 * @codeCoverageIgnore Pure markup; verified by eye in wp-env.
	 */
	public function get_icon() {
		$src  = CASHU_WC_URL . 'assets/images/bitcoin-logo.svg';
		$icon = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="max-width:40px;" />';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Merchant-side: keep the Cashu chip in the gateway settings page header
	 * even though the customer-facing icon is now the Bitcoin mark.
	 *
	 * @codeCoverageIgnore Pure markup; verified by eye in wp-env.
	 */
	public function admin_options() {
		$chip = CASHU_WC_URL . 'assets/images/cashu-logo-chip.png';
		echo '<img src="' . esc_url( $chip ) . '" alt="Cashu" style="float:right;max-width:64px;margin:0 0 8px 12px;" />';
		parent::admin_options();
	}

	/**
	 * Cache-buster for plugin-shipped assets. Uses the file's mtime in dev /
	 * debug contexts so iterating doesn't require touching CASHU_WC_VERSION,
	 * and falls back to the plugin version in production.
	 */
	private function asset_version( string $relative_path ): string {
		$path = CASHU_WC_PATH . $relative_path;
		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ) {
			if ( is_readable( $path ) ) {
				$mtime = filemtime( $path );
				if ( false !== $mtime ) {
					return CASHU_WC_VERSION . '.' . $mtime;
				}
			}
		}
		return CASHU_WC_VERSION;
	}

	/**
	 * Register gateway scripts / styles
	 */
	public function enqueue_scripts() {
		// Main checkout
		wp_register_script(
			'cashu-checkout',
			CASHU_WC_URL . 'assets/js/cashu/checkout.js',
			array( 'jquery', 'wp-i18n' ),
			$this->asset_version( 'assets/js/cashu/checkout.js' ),
			false // head
		);

		wp_localize_script(
			'cashu-checkout',
			'cashu_wc',
			array(
				'rest_root'     => esc_url_raw( rest_url( 'cashu-wc/v1/' ) ),
				'confirm_route' => 'confirm-melt-quote',
				'claim_route'   => 'claim-melt-quote',
				'symbol'        => CASHU_WC_BIP177_SYMBOL,

				// Per-tab QR centre-icon overlay. JS swaps src by currentMode;
				// unified hides the overlay entirely because the BIP-321 payload
				// is dual-protocol — branding it with either logo would mislead,
				// and the extra payload data also leaves less error-correction
				// headroom for an opaque centre cutout.
				'qr_icons'      => array(
					'cashu'     => esc_url_raw( CASHU_WC_URL . 'assets/images/cashu-logo.png' ),
					'lightning' => esc_url_raw( CASHU_WC_URL . 'assets/images/lightning_badge.svg' ),
				),

				'i18n'          => array(
					// General / bootstrap
					'data_incomplete'         => __( 'Payment data incomplete, please refresh and try again.', 'cashu-for-woocommerce' ),
					'invoice_failed'          => __( 'Could not prepare the invoice, please refresh and try again', 'cashu-for-woocommerce' ),

					// QR interactions
					'copied'                  => __( 'Copied!', 'cashu-for-woocommerce' ),
					'waiting_for_payment'     => __( 'Waiting for payment...', 'cashu-for-woocommerce' ),
					'connecting_to_mint'      => __( 'Connecting to mint...', 'cashu-for-woocommerce' ),
					'qr_hint_unified'         => __( 'Scan or tap to copy, then paste into your wallet.', 'cashu-for-woocommerce' ),
					'qr_hint_cashu'           => __( 'Scan or tap to copy, then paste into your Cashu wallet.', 'cashu-for-woocommerce' ),
					'qr_hint_lightning'       => __( 'Scan or tap to copy, then paste into your Lightning wallet.', 'cashu-for-woocommerce' ),

					// Tabs
					'tab_unified'             => __( 'Auto', 'cashu-for-woocommerce' ),
					'tab_cashu'               => __( 'Cashu', 'cashu-for-woocommerce' ),
					'tab_lightning'           => __( 'Lightning', 'cashu-for-woocommerce' ),

					// Recovery flow (Lightning leg)
					'payment_failed'          => __( 'Payment failed. Please copy the recovery token below.', 'cashu-for-woocommerce' ),
					'waiting_confirmation'    => __( 'Waiting for payment confirmation...', 'cashu-for-woocommerce' ),
					'recovering_proofs'       => __( 'Recovering payment from mint...', 'cashu-for-woocommerce' ),
					'recovery_failed_contact' => __( 'Could not recover payment. Please contact the merchant.', 'cashu-for-woocommerce' ),
					'reconciling_with_mint'   => __( 'Reconciling with mint...', 'cashu-for-woocommerce' ),
					'previous_attempt_failed' => __( 'A previous payment attempt didn\'t reach the mint. Your wallet may have refunded the proofs — please try again.', 'cashu-for-woocommerce' ),

					// Lightning leg progress
					'payment_received'        => __( 'Payment received by our mint...', 'cashu-for-woocommerce' ),
					'paying_invoice'          => __( 'Paying invoice...', 'cashu-for-woocommerce' ),
					'confirming_payment'      => __( 'Confirming payment...', 'cashu-for-woocommerce' ),
					'payment_confirmed'       => __( 'Payment confirmed!', 'cashu-for-woocommerce' ),
					'settling_at_mint'        => __( 'Mint is settling the Lightning payment...', 'cashu-for-woocommerce' ),

					// Change
					'change_from_network'     => __( 'Change From Network Fee Reserve', 'cashu-for-woocommerce' ),

					// Order status polling
					'invoice_expired'         => __( 'Invoice has expired', 'cashu-for-woocommerce' ),

					/* translators: 1: time remaining, formatted like MM:SS */
					'invoice_expires_in'      => __( 'Invoice expires in: %1$s', 'cashu-for-woocommerce' ),
				),
			)
		);

		// Change box
		wp_register_script(
			'cashu-thanks',
			CASHU_WC_URL . 'assets/js/frontend/thanks.js',
			array( 'wp-i18n' ),
			$this->asset_version( 'assets/js/frontend/thanks.js' ),
			true
		);

		wp_localize_script(
			'cashu-thanks',
			'cashu_wc_thanks',
			array(
				'symbol' => CASHU_WC_BIP177_SYMBOL,
				'i18n'   => array(
					'title'       => __( 'Your Cashu change', 'cashu-for-woocommerce' ),
					'dismiss'     => __( 'Dismiss', 'cashu-for-woocommerce' ),

					'lead'        => __(
						'Your Lightning payment fees were lower than expected. Here\'s the difference back as Cashu change. Copy each token into your Cashu wallet to claim it.',
						'cashu-for-woocommerce'
					),

					/* translators: %s is the rendered <a href="https://cashu.me">cashu.me</a> link */
					'no_wallet'   => __(
						'New to Cashu? Try %s',
						'cashu-for-woocommerce'
					),

					/* translators: %s is the word "Important:" (label) shown before the message */
					'important'   => __( 'Important:', 'cashu-for-woocommerce' ),

					'tip'         => __(
						'Copy your change now — it disappears soon.',
						'cashu-for-woocommerce'
					),

					'dust_badge'  => __( 'Dust', 'cashu-for-woocommerce' ),

					'dust_note'   => __(
						'Too small to spend on its own — save it with other change.',
						'cashu-for-woocommerce'
					),

					'copy'        => __( 'Copy', 'cashu-for-woocommerce' ),
					'copied'      => __( 'Copied', 'cashu-for-woocommerce' ),
					'copy_failed' => __( 'Copy failed', 'cashu-for-woocommerce' ),
					'show'        => __( 'Show', 'cashu-for-woocommerce' ),
					'hide'        => __( 'Hide', 'cashu-for-woocommerce' ),

					'change'      => __( 'Change', 'cashu-for-woocommerce' ),

					/* translators: 1: bitcoin symbol, 2: amount in sats, 3: mint hostname */
					'meta_amount' => __( 'Amount: %1$s%2$d, %3$s', 'cashu-for-woocommerce' ),
				),
			)
		);

		// Gateway CSS
		wp_register_style(
			'cashu-public',
			CASHU_WC_URL . 'assets/css/public.css',
			array(),
			$this->asset_version( 'assets/css/public.css' )
		);
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$total = (float) $order->get_total();

		if ( 0.0 === $total ) {
			// No payment needed
			$order->payment_complete();
		} else {
			try {
				$this->setup_cashu_payment( $order );
			} catch ( \Throwable $e ) {
				Logger::error( 'Could not setup Cashu payment: ' . $e->getMessage() );
				if ( $e instanceof AmountLimitException ) {
					// The mint / LNURL service just rejected this amount, so
					// the cached limits snapshot is demonstrably behind —
					// refresh it now so is_available() hides the gateway for
					// subsequent out-of-range carts.
					MintLimits::refresh( true );
				}
				wc_add_notice( $this->classify_setup_error( $e ), 'error' );
				return array( 'result' => 'failure' );
			}
		}

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Setup cashu checkout and redirect to payment form
	 *
	 * @param WC_Order $order Order.
	 */
	private function setup_cashu_payment( WC_Order $order ): void {
		// Refuse to (re-)initialise a paid order. Calling update_status below
		// would silently regress 'processing' or 'completed' back to 'pending',
		// orphaning the customer's already-settled payment.
		if ( $order->is_paid() ) {
			Logger::error( 'setup_cashu_payment called on already-paid order ' . $order->get_id() . '; refusing to overwrite payment state.' );
			return;
		}

		$order_id = $order->get_id();

		// Serialise concurrent setup against the same order. Two browser
		// tabs hitting /checkout/order-pay/{id} simultaneously can both
		// find meta stale and both call this method, racing on the mint
		// POSTs and meta writes — the second wins the save() and orphans
		// the first tab's quote at the mint.
		$lock_token = OrderLock::acquire( $order_id, 'setup', 60 );
		if ( null === $lock_token ) {
			// Another request is currently doing setup. Wait for it to
			// finish, then refresh the caller's meta from DB so they see
			// what the holder just wrote.
			Logger::debug( 'setup_cashu_payment waiting on setup lock for order ' . $order_id );
			if ( ! OrderLock::wait_for_release( $order_id, 'setup', 30 ) ) {
				Logger::error( 'setup_cashu_payment lock contention timed out for order ' . $order_id );
			}
			$order->read_meta_data( true );
			return;
		}

		try {
			// Re-fetch the order under the lock. A previous holder may
			// have just finished — re-doing setup would needlessly burn
			// another mint quote.
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh ) {
				Logger::error( 'setup_cashu_payment could not re-fetch order ' . $order_id );
				return;
			}
			if ( $fresh->is_paid() ) {
				return;
			}

			// Also take the 'pay' scope for the status write + quote
			// mutations, so setup serialises against PayController /
			// mark_paid / MeltReconciler. Without it, a settlement landing
			// between our re-check and update_status would be regressed to
			// 'pending', and quote rotation (archive_melt_quote) could race
			// an in-flight melt's pending marker. Lock ordering is always
			// setup → pay (no path acquires them in reverse), so this can't
			// deadlock. TTL matches PayController's melt window.
			$pay_token = OrderLock::acquire( $order_id, 'pay', 120 );
			if ( null === $pay_token ) {
				// A settlement is mid-flight; mutating status or quotes
				// under it is exactly the race this lock closes. Bail and
				// let the caller re-render from current meta.
				Logger::debug( 'setup_cashu_payment skipping: pay lock held for order ' . $order_id );
				$order->read_meta_data( true );
				return;
			}

			try {
				/**
				 * Filter the order status for cashu payment (Default: 'pending').
				 *
				 * @param string $status The default status.
				 * @param object $order  The order object.
				 */
				$process_payment_status = apply_filters(
					'cashu_wc_process_payment_order_status',
					OrderStatus::PENDING,
					$fresh
				);

				// Re-check is_paid from a fresh DB read immediately before
				// update_status. A payment_complete that finished JUST before
				// we took the pay lock (cashu leg PayController, LN leg
				// mark_paid, or MeltReconciler) would otherwise be silently
				// un-paid by update_status('pending').
				$latest = wc_get_order( $order_id );
				if ( $latest && $latest->is_paid() ) {
					return;
				}

				// Set order status.
				$fresh->update_status(
					$process_payment_status,
					_x( 'Awaiting Cashu payment', 'Cashu payment method', 'cashu-for-woocommerce' )
				);

				// Determine invoice amount in sats (merchant receives this).
				$order_total_sats = $this->get_total_sats( $fresh );

				// Create or reuse melt quote (vendor payment side), store fee reserve,
				// set the headline _cashu_melt_total = amount + fee_reserve + input buffer.
				$this->ensure_melt_quote_for_order( $fresh, $order_total_sats );

				// Create or reuse mint quote (customer payment side). The customer pays
				// this BOLT11 via LN; the quote_id is what the browser uses to claim
				// proofs from the mint. Locking this server-side means a page reload
				// or browser switch can never lose the quote_id and orphan the
				// customer's payment.
				$melt_total = absint( $fresh->get_meta( '_cashu_melt_total', true ) );
				$this->ensure_mint_quote_for_order( $fresh, $melt_total );

				$fresh->save();

				// Mirror writes back onto the caller's $order so they don't
				// have to re-read from DB themselves.
				$order->read_meta_data( true );
			} finally {
				OrderLock::release( $order_id, 'pay', $pay_token );
			}
		} finally {
			OrderLock::release( $order_id, 'setup', $lock_token );
		}
	}

	/**
	 * Map a setup failure into a customer-facing message. Avoids leaking
	 * raw mint/LN errors but tells the user enough to know whether to
	 * retry or contact the store.
	 */
	private function classify_setup_error( \Throwable $e ): string {
		$msg = $e->getMessage();

		// Checked before the string sniffs: limit messages mention
		// "lightning address" / "mint" too and would misclassify below.
		if ( $e instanceof AmountLimitException ) {
			return __( 'This order total is outside the amounts the store can currently accept over Lightning. Please contact the store if you believe this is an error.', 'cashu-for-woocommerce' );
		}

		if ( false !== stripos( $msg, 'lightning address' )
			|| false !== stripos( $msg, 'LNURL' )
			|| false !== stripos( $msg, 'invoice' )
		) {
			return __( "We couldn't fetch a Lightning invoice for your payment. Please contact the store — the merchant's Lightning address may need attention.", 'cashu-for-woocommerce' );
		}

		if ( false !== stripos( $msg, 'mint quote request failed' )
			|| false !== stripos( $msg, 'mint melt request failed' )
			|| false !== stripos( $msg, 'response is not json' )
		) {
			return __( "We couldn't reach the Cashu mint right now. Please reload to try again, or contact the store if this keeps happening.", 'cashu-for-woocommerce' );
		}

		if ( false !== stripos( $msg, 'price quote' ) ) {
			return __( "We couldn't fetch a current BTC price. Please reload to try again.", 'cashu-for-woocommerce' );
		}

		if ( false !== stripos( $msg, 'not configured' ) ) {
			return __( 'Cashu payment is temporarily unavailable. Please contact the store.', 'cashu-for-woocommerce' );
		}

		return __( 'Cashu payment setup failed, please try again.', 'cashu-for-woocommerce' );
	}

	/**
	 * Determine the invoice amount in sats, reusing any existing value.
	 */
	private function get_total_sats( WC_Order $order ): int {
		// Return existing sats amount if quote is still valid
		$order_total_sats = absint( $order->get_meta( '_cashu_spot_total', true ) );
		$quoted_at        = absint( $order->get_meta( '_cashu_spot_time', true ) );
		if ( $order_total_sats > 0 && $quoted_at > time() - self::QUOTE_EXPIRY_SECS ) {
			return $order_total_sats;
		}

		// Window lapsed. Before repricing, try to slide it: while the
		// customer's BOLT11 is still payable and spot drift stays inside
		// the tolerance band, the standing quote pair remains the offer.
		if ( $order_total_sats > 0 && SpotWindow::maybe_slide( $order ) ) {
			return $order_total_sats;
		}

		$total = (float) $order->get_total();
		$quote = CashuHelper::fiatToSats( $total, $order->get_currency() );

		$order_total_sats = absint( $quote['sats'] ?? 0 );
		if ( $order_total_sats <= 0 ) {
			Logger::error( 'Cashu quote failed, sats amount is invalid.' );
			throw new \RuntimeException( 'Could not get price quote in bitcoin.' );
		}

		$order->update_meta_data( '_cashu_spot_total', $order_total_sats );
		$order->update_meta_data( '_cashu_spot_time', $quote['quoted_at'] );
		$order->update_meta_data( '_cashu_spot_btc', $quote['btc_price'] );
		$order->update_meta_data( '_cashu_spot_source', $quote['source'] );

		// Melt and mint quote meta is intentionally NOT cleared here — once
		// either has been issued against this order it may already be PAID or
		// PENDING at the mint, and deleting it would orphan the customer's
		// payment. ensure_melt_quote_for_order / ensure_mint_quote_for_order
		// each consult the mint and archive before rotating.

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: Bitcoin symbol, %2$s: Amount in sats, %3$s: ISO 4217 currency code (eg: USD), %4$s: BTC Spot price, %5$s: quote source */
				__( 'Cashu quote: %1$s%2$s (BTC/%3$s: %4$s) from %5$s', 'cashu-for-woocommerce' ),
				CASHU_WC_BIP177_SYMBOL,
				$order_total_sats,
				$order->get_currency(),
				(string) ( $quote['btc_price'] ?? '' ),
				$quote['source']
			)
		);

		return $order_total_sats;
	}

	/**
	 * Ensures we have a melt quote at the trusted mint so we can pay
	 * the order total in sats to the vendor's lightning address.
	 */
	private function ensure_melt_quote_for_order( \WC_Order $order, int $order_total_sats ): void {
		// Check settings are ok
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( 'Cashu gateway is not configured.' );
		}

		// Never touch the melt quote while a melt is mid-flight at the mint.
		// The pending marker means proofs may already be committed against
		// the current quote — rotating here (including the changed-mint
		// branch below, which archives WITHOUT consulting the issuing mint)
		// would drop the marker and orphan a settling payment. The marker
		// lifecycle belongs to resolve_pending_melt / MeltReconciler, which
		// clear it on a positive UNPAID or after the 24h age-out.
		if ( '' !== (string) $order->get_meta( '_cashu_melt_pending_quote_id', true ) ) {
			return;
		}

		// Use existing melt quote?
		$quote_id     = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		$quote_expiry = absint( $order->get_meta( '_cashu_melt_quote_expiry', true ) );
		$melt_mint    = (string) $order->get_meta( '_cashu_melt_mint', true );

		if ( '' !== $quote_id ) {
			// Only consult the mint that issued the quote. Normalise on
			// both sides so a slightly-different but logically-equivalent
			// option value (re-saved with a capitalised host, default
			// port, etc.) doesn't fall into the rotation branch and risk
			// orphaning a paid quote.
			if ( MintClient::normalize_url( $melt_mint ) === MintClient::normalize_url( $this->trusted_mint ) ) {
				// Hard guard: never rotate a quote with proofs bound to it.
				// PENDING means the mint is mid-LN-payment; rotating would
				// orphan the customer's payment. PAID means the mint already
				// settled. Unknown state (empty) means the mint was unreachable
				// — preserve, since rotating on a network blip would orphan a
				// possibly-paid quote (recovery would have to come through the
				// admin archive meta-box).
				$mint_state   = MintClient::melt_quote_state_cached( $this->trusted_mint, $quote_id );
				$state_string = isset( $mint_state['state'] ) ? (string) $mint_state['state'] : '';
				if ( 'PAID' === $state_string || 'PENDING' === $state_string || '' === $state_string ) {
					return;
				}

				// UNPAID — keep if the BOLT11 is still valid at the mint.
				if ( $quote_expiry > time() ) {
					return;
				}
			}

			// Genuinely rotating: either UNPAID + expired at the same mint, or
			// the admin changed the trusted mint setting (in which case we
			// cannot safely query the original mint from here). Archive first
			// so an orphan can still be traced forensically.
			$this->archive_melt_quote( $order );
		}

		// Create LN invoice for the headline order amount (merchant receives this).
		$comment = sprintf(
			/* translators: %1$s: Order ID  */
			__( 'Order: #%1$s', 'cashu-for-woocommerce' ),
			(string) $order->get_id()
		);
		$invoice = LightningAddress::get_invoice( $this->ln_address, $order_total_sats, $comment );
		if ( ! is_string( $invoice ) || '' === $invoice ) {
			throw new \RuntimeException( 'Failed to obtain Lightning invoice.' );
		}

		// Request melt quote to pay the vendor LN invoice
		$quote       = MintClient::request_melt_quote( $this->trusted_mint, $invoice );
		$quote_id    = sanitize_text_field( (string) ( $quote['quote'] ?? '' ) );
		$expiry      = absint( $quote['expiry'] ?? 0 );
		$amount      = absint( $quote['amount'] ?? 0 );
		$fee_reserve = absint( $quote['fee_reserve'] ?? 0 );
		$unit        = (string) ( $quote['unit'] ?? '' );

		if ( '' === $quote_id || $amount <= 0 || $expiry <= 0 || 'sat' !== $unit ) {
			throw new \RuntimeException( 'Invalid melt quote response from mint.' );
		}

		// The mint decodes the BOLT11 we handed it, so its quoted amount is
		// the invoice's real amount. It MUST equal what we asked the LNURL
		// service to invoice (LUD-06 requires exact-amount invoices) — a
		// mismatch means the Lightning address provider returned a wrong-
		// amount invoice, and proceeding would let the order settle for the
		// wrong number of sats.
		if ( $amount !== $order_total_sats ) {
			Logger::error( 'Lightning invoice amount mismatch for order ' . $order->get_id() . ': requested ' . $order_total_sats . ' sat, invoice is for ' . $amount . ' sat.' );
			throw new \RuntimeException( 'Lightning invoice amount mismatch.' );
		}

		// Persist the quote context for confirm step later.
		$order->update_meta_data( '_cashu_melt_quote_id', $quote_id );
		$order->update_meta_data( '_cashu_melt_quote_expiry', $expiry );
		$order->update_meta_data( '_cashu_melt_mint', $this->trusted_mint );
		// Snapshot the underlying BOLT11 so the melt archive (and any out-
		// of-band forensic flow) can reconstruct what was actually being
		// paid — without this, an orphaned PAID merchant melt is opaque.
		$order->update_meta_data( '_cashu_melt_quote_request', $invoice );
		// Snapshot the LN address that the invoice was actually fetched
		// against. mark_paid's order note should reflect *that* address,
		// not whatever's in the option at settlement time — the admin may
		// have rotated the address between quote and payment.
		$order->update_meta_data( '_cashu_invoice_ln_address', $this->ln_address );

		// Stash the invoice's payment_hash so the browser-claim endpoint can
		// verify a preimage cryptographically (no mint round-trip).
		$payment_hash = Bolt11::paymentHash( $invoice );
		if ( null !== $payment_hash ) {
			$order->update_meta_data( '_cashu_payment_hash', $payment_hash );
		}

		// Headline amount the customer must cover: invoice + mint's lightning fee
		// reserve + a small buffer for the trusted mint's per-proof input fees on
		// the melt. The mint charges input_fee_ppk on every input proof it accepts;
		// the buffer absorbs that so the melt clears. Anything not consumed by
		// input fees is returned to the payer as change proofs (cashu-ts saves them
		// for the lightning leg; NUT-18 wallets restore them from the merchant's
		// response on the cashu leg). 1% / min 2 sats is well clear of typical mint
		// fees (popcount × ppk / 1000 ≈ 0.1%) and stays inside spot-rate noise.
		$bare_total       = $amount + $fee_reserve;
		$input_fee_buffer = max( 2, (int) ceil( $bare_total * 0.01 ) );
		$melt_total       = $bare_total + $input_fee_buffer;
		$order->update_meta_data( '_cashu_melt_total', $melt_total );

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: sats invoice amount, %2$s: lightning fee reserve sats, %3$s: mint input fee buffer sats, %4$s: total required sats, %5$s: Melt Quote ID */
				__( "Cashu melt quote created:\nInvoice: %1\$s\nLightning fee reserve: %2\$s\nMint fee buffer: %3\$s\nTotal: %4\$s\nQuote ID: %5\$s", 'cashu-for-woocommerce' ),
				(string) CASHU_WC_BIP177_SYMBOL . $amount,
				(string) CASHU_WC_BIP177_SYMBOL . $fee_reserve,
				(string) CASHU_WC_BIP177_SYMBOL . $input_fee_buffer,
				(string) CASHU_WC_BIP177_SYMBOL . $melt_total,
				$quote_id,
			)
		);
	}

	/**
	 * Ensure the order has an active mint quote (NUT-04) on the trusted mint so
	 * the customer has a stable BOLT11 to pay. The quote is created server-side
	 * and the quote_id persists on the order — page refreshes, browser switches,
	 * or cleared local storage can never lose the link to a paid quote.
	 *
	 * Old quotes are archived rather than overwritten when they expire, so
	 * forensic / reconciliation flows can still recover stranded payments.
	 */
	private function ensure_mint_quote_for_order( \WC_Order $order, int $amount_sats ): void {
		$existing_id     = (string) $order->get_meta( '_cashu_mint_quote_id', true );
		$existing_expiry = absint( $order->get_meta( '_cashu_mint_quote_expiry', true ) );
		$existing_amount = absint( $order->get_meta( '_cashu_mint_quote_amount', true ) );
		$existing_mint   = (string) $order->get_meta( '_cashu_mint_quote_mint', true );

		if ( '' !== $existing_id && $existing_amount === $amount_sats ) {
			// Keep the existing quote if it hasn't fully expired at the mint.
			// expiry == 0 means the mint doesn't advertise an expiry; treat as
			// "still valid" — the cashu spec allows null/missing.
			if ( 0 === $existing_expiry || $existing_expiry > time() ) {
				return;
			}
		}

		// Before rotating, ask the mint for the existing quote's state. We must
		// NEVER abandon a paid quote — the customer's funds are bound to that
		// quote_id and rotating would orphan them in _cashu_archived_mint_quotes
		// where the browser's auto-recovery doesn't look. If the mint says the
		// quote is PAID or ISSUED, the time/amount checks above are irrelevant —
		// the quote is the customer's payment, full stop. An empty/unknown
		// return means the mint was unreachable (or the trusted mint setting
		// changed since the quote was issued, in which case we can't safely
		// query) — preserve too, since rotating on a network blip risks
		// orphaning a paid quote.
		if ( '' !== $existing_id ) {
			// _cashu_mint_quote_mint was introduced after some orders shipped;
			// fall back to the current trusted mint so legacy orders still
			// consult something (assumed to be the same mint they were
			// originally issued at, since that's the only mint we knew).
			$lookup_mint = '' !== $existing_mint ? $existing_mint : $this->trusted_mint;
			if ( MintClient::normalize_url( $lookup_mint ) === MintClient::normalize_url( $this->trusted_mint ) ) {
				$state = MintClient::mint_quote_state( $lookup_mint, $existing_id );
				if ( 'PAID' === $state || 'ISSUED' === $state || '' === $state ) {
					return;
				}
			}
		}

		// Archive the outgoing quote (if any) before rotating.
		if ( '' !== $existing_id ) {
			$archive_raw = (string) $order->get_meta( '_cashu_archived_mint_quotes', true );
			$archive     = is_string( $archive_raw ) && '' !== $archive_raw
				? (array) json_decode( $archive_raw, true )
				: array();
			$archive[]   = array(
				'quote'   => $existing_id,
				'request' => (string) $order->get_meta( '_cashu_mint_quote_request', true ),
				'mint'    => $existing_mint,
				'amount'  => $existing_amount,
				'expiry'  => $existing_expiry,
				'created' => absint( $order->get_meta( '_cashu_mint_quote_created', true ) ),
			);
			$encoded     = wp_json_encode( $archive );
			if ( is_string( $encoded ) ) {
				$order->update_meta_data( '_cashu_archived_mint_quotes', $encoded );
			}
		}

		$quote = MintClient::request_mint_quote( $this->trusted_mint, $amount_sats );

		$quote_id      = sanitize_text_field( (string) ( $quote['quote'] ?? '' ) );
		$quote_request = (string) ( $quote['request'] ?? '' );
		$quote_expiry  = absint( $quote['expiry'] ?? 0 );

		if ( '' === $quote_id || '' === $quote_request ) {
			throw new \RuntimeException( 'Invalid mint quote response from mint.' );
		}

		$order->update_meta_data( '_cashu_mint_quote_id', $quote_id );
		$order->update_meta_data( '_cashu_mint_quote_request', $quote_request );
		$order->update_meta_data( '_cashu_mint_quote_amount', $amount_sats );
		$order->update_meta_data( '_cashu_mint_quote_expiry', $quote_expiry );
		$order->update_meta_data( '_cashu_mint_quote_mint', $this->trusted_mint );
		$order->update_meta_data( '_cashu_mint_quote_created', time() );

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: BTC symbol, %2$d: amount in sats, %3$s: mint quote id */
				__( "Cashu mint quote created:\nAmount: %1\$s%2\$d\nQuote ID: %3\$s", 'cashu-for-woocommerce' ),
				CASHU_WC_BIP177_SYMBOL,
				$amount_sats,
				$quote_id
			)
		);
	}

	/**
	 * Append the order's current melt quote to its archived list before
	 * rotation, so an orphaned PAID/PENDING quote can still be discovered
	 * after the fact.
	 */
	private function archive_melt_quote( \WC_Order $order ): void {
		$current = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		if ( '' === $current ) {
			return;
		}
		$raw       = (string) $order->get_meta( '_cashu_archived_melt_quotes', true );
		$archive   = '' !== $raw ? (array) json_decode( $raw, true ) : array();
		$archive[] = array(
			'quote'        => $current,
			'request'      => (string) $order->get_meta( '_cashu_melt_quote_request', true ),
			'expiry'       => absint( $order->get_meta( '_cashu_melt_quote_expiry', true ) ),
			'mint'         => (string) $order->get_meta( '_cashu_melt_mint', true ),
			'amount'       => absint( $order->get_meta( '_cashu_melt_total', true ) ),
			'payment_hash' => (string) $order->get_meta( '_cashu_payment_hash', true ),
			'ln_address'   => (string) $order->get_meta( '_cashu_invoice_ln_address', true ),
		);
		$encoded   = wp_json_encode( $archive );
		if ( is_string( $encoded ) ) {
			$order->update_meta_data( '_cashu_archived_melt_quotes', $encoded );
		}

		// Clear any pending-melt marker pointing at the rotated quote so
		// MeltReconciler and the polling endpoint don't keep probing a quote
		// we've moved on from. Rotation only happens when the mint positively
		// returned UNPAID (see ensure_melt_quote_for_order), so proofs were
		// never consumed and the marker is safely dead. Also flush the
		// shared mint-state transient cached against the rotated quote id.
		$pending_quote_id = (string) $order->get_meta( '_cashu_melt_pending_quote_id', true );
		if ( $pending_quote_id === $current ) {
			$order->delete_meta_data( '_cashu_melt_pending_quote_id' );
			$order->delete_meta_data( '_cashu_melt_pending_at' );
		}
		MintClient::flush_melt_quote_state( $current );
	}

	public function receipt_page( $order_id ) {
		// Render once per request — see $receipt_rendered. The JS only ever
		// binds the first #cashu-pay-root, so a second render would be dead
		// markup with a duplicate element id.
		if ( $this->receipt_rendered ) {
			return;
		}
		$this->receipt_rendered = true;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'cashu-for-woocommerce' ) . '</p>';
			return;
		}

		// Check payment is for our gateway
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		// Already paid — never re-run setup (which would call update_status to
		// pending and silently un-pay the order). Show a notice and link the
		// admin/customer to the order-received page instead. The admin recovery
		// meta-box uses this same URL, so paid orders need to land here safely.
		if ( $order->is_paid() ) {
			echo '<p>' . esc_html__( 'This order has already been paid.', 'cashu-for-woocommerce' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( $order->get_checkout_order_received_url() ) . '">';
			echo esc_html__( 'View order', 'cashu-for-woocommerce' );
			echo '</a></p>';
			return;
		}

		// Fallback: ensure both quotes are present and not stale. We retry setup
		// when the spot quote expired, or when either the melt quote or the mint
		// quote is missing (e.g. an earlier setup failed mid-way because the mint
		// was briefly unreachable).
		$quote_expiry  = absint( $order->get_meta( '_cashu_melt_quote_expiry', true ) );
		$mint_quote_id = (string) $order->get_meta( '_cashu_mint_quote_id', true );
		$spot_time     = absint( $order->get_meta( '_cashu_spot_time', true ) );
		$spot_expiry   = $spot_time + self::QUOTE_EXPIRY_SECS;
		$setup_error   = '';
		if ( $spot_expiry < time()
			|| $quote_expiry < $spot_expiry
			|| '' === $mint_quote_id
		) {
			try {
				$this->setup_cashu_payment( $order );
				// Reset spot expiry
				$spot_time   = absint( $order->get_meta( '_cashu_spot_time', true ) );
				$spot_expiry = $spot_time + self::QUOTE_EXPIRY_SECS;
			} catch ( \Throwable $e ) {
				$setup_error = $this->classify_setup_error( $e );
				Logger::error( 'Could not setup Cashu payment on receipt page: ' . $e->getMessage() );
			}
		}

		$melt_total         = absint( $order->get_meta( '_cashu_melt_total', true ) );
		$quote_id           = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		$trusted_mint       = (string) $order->get_meta( '_cashu_melt_mint', true );
		$mint_quote_id      = (string) $order->get_meta( '_cashu_mint_quote_id', true );
		$mint_quote_request = (string) $order->get_meta( '_cashu_mint_quote_request', true );
		$mint_quote_amount  = absint( $order->get_meta( '_cashu_mint_quote_amount', true ) );
		$mint_quote_expiry  = absint( $order->get_meta( '_cashu_mint_quote_expiry', true ) );

		// If setup failed (or never ran) and the essential meta is missing,
		// rendering the JS widget would either show a broken QR or, worse,
		// hand the JS a spot_expiry of 900 (epoch + 15 min) which the
		// polling loop reads as already-expired and silently redirects the
		// customer back to the cart. Render an explicit error UI instead.
		$payment_data_missing = ( 0 === $spot_time || '' === $mint_quote_id || '' === $quote_id || $melt_total <= 0 );
		if ( $payment_data_missing ) {
			$message = '' !== $setup_error
				? $setup_error
				: __( 'Cashu payment data is missing for this order. Please reload the page to retry, or contact the store if the problem persists.', 'cashu-for-woocommerce' );
			echo '<p class="woocommerce-error" role="alert">' . esc_html( $message ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( $order->get_checkout_payment_url() ) . '">';
			echo esc_html__( 'Retry payment', 'cashu-for-woocommerce' );
			echo '</a></p>';
			return;
		}

		$order_key   = $order->get_order_key();
		$pay_route   = sprintf( 'cashu-wc/v1/pay/%d/%s', $order_id, $order_key );
		$pay_url     = rest_url( $pay_route );
		$payment_id  = PayController::payment_id_for( $order_id, $order_key );
		$description = sprintf(
			/* translators: %1$d: order number, %2$s: site name */
			__( 'Order #%1$d at %2$s', 'cashu-for-woocommerce' ),
			$order_id,
			get_bloginfo( 'name' )
		);

		wp_enqueue_script( 'cashu-checkout' );
		wp_enqueue_style( 'cashu-public' );

		$paths_option = CashuPaths::sanitize(
			get_option( 'cashu_paths', CashuPaths::DEFAULT_PATHS )
		);
		$enabled_keys = CashuPaths::enabled_keys( $paths_option );
		$default_tab  = CashuPaths::default_path(
			$paths_option,
			(string) get_option( 'cashu_default_path', CashuPaths::DEFAULT_PATH )
		);

		$tab_labels = array(
			'unified'   => __( 'Auto', 'cashu-for-woocommerce' ),
			'cashu'     => __( 'Cashu', 'cashu-for-woocommerce' ),
			'lightning' => __( 'Lightning', 'cashu-for-woocommerce' ),
		);

		echo '<div id="cashu-pay-root"
			data-order-id="' . esc_attr( (string) $order_id ) . '"
			data-order-key="' . esc_attr( $order_key ) . '"
			data-return-url="' . esc_url( $this->get_return_url( $order ) ) . '"
			data-expected-amount="' . esc_attr( (string) $melt_total ) . '"
			data-melt-quote-id="' . esc_attr( $quote_id ) . '"
			data-spot-quote-expiry="' . esc_attr( (string) $spot_expiry ) . '"
			data-trusted-mint="' . esc_attr( $trusted_mint ) . '"
			data-mint-quote-id="' . esc_attr( $mint_quote_id ) . '"
			data-mint-quote-request="' . esc_attr( $mint_quote_request ) . '"
			data-mint-quote-amount="' . esc_attr( (string) $mint_quote_amount ) . '"
			data-mint-quote-expiry="' . esc_attr( (string) $mint_quote_expiry ) . '"
			data-pay-callback="' . esc_url( $pay_url ) . '"
			data-payment-id="' . esc_attr( $payment_id ) . '"
			data-description="' . esc_attr( $description ) . '"
			data-default-tab="' . esc_attr( $default_tab ) . '"
		></div>';

		?>
		<section id="cashu-payment" class="cashu-checkout" aria-label="<?php echo esc_attr__( 'Cashu payment', 'cashu-for-woocommerce' ); ?>">
			<div class="cashu-amount-box cashu-center">
				<div class="cashu-payamount"><?php esc_html_e( 'Amount Due', 'cashu-for-woocommerce' ); ?></div>
				<h2 class="cashu-amount">
					<?php echo esc_html( CASHU_WC_BIP177_SYMBOL . $melt_total ); ?>
				</h2>
			</div>
			<div class="cashu-box">
				<div id="cashu-status" class="cashu-status" role="status" aria-live="polite">
					<?php
					esc_html_e( 'Status: Waiting for payment...', 'cashu-for-woocommerce' )
					?>
				</div>

				<?php
				if ( count( $enabled_keys ) > 1 ) :
					?>
					<div class="cashu-tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Payment options', 'cashu-for-woocommerce' ); ?>">
						<?php
						foreach ( $enabled_keys as $key ) :
							$is_active = ( $key === $default_tab );
							?>
							<button
								type="button"
								class="cashu-tab<?php echo $is_active ? ' is-active' : ''; ?>"
								role="tab"
								aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
								data-cashu-tab="<?php echo esc_attr( $key ); ?>"
							><?php echo esc_html( $tab_labels[ $key ] ); ?></button>
						<?php endforeach; ?>
					</div>
					<?php
				endif;
				?>

				<div class="cashu-qr-wrap">
					<div class="cashu-qr" data-cashu-qr>
						<!-- JS renders QR here, canvas or img is fine -->
					</div>

					<div class="cashu-qr-icon" data-cashu-qr-icon hidden aria-hidden="true">
						<img src="<?php echo esc_url( $this->icon ); ?>" alt="">
					</div>

					<div class="cashu-qr-check" data-cashu-qr-check aria-hidden="true">
						<svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" /><path pathLength="1" d="M15.5 27.5l7.5 7.5L36.5 20" /></svg>
					</div>

				</div>

				<p class="cashu-qr-hint" data-cashu-qr-hint></p>

				<div class="cashu-recovery" data-cashu-recovery hidden>
					<div class="cashu-recovery-label">
						<?php esc_html_e( 'Copy the token below and paste it into a Cashu wallet to reclaim your sats.', 'cashu-for-woocommerce' ); ?>
					</div>
					<button type="button" class="cashu-recovery-copy" data-cashu-recovery-copy>
						<?php esc_html_e( 'Copy recovery token', 'cashu-for-woocommerce' ); ?>
					</button>
				</div>

			</div>

		</section>

		<div class="cashu-feenote">
			<?php
			printf(
				/* translators: %1$s: Mint hostname */
				esc_html__( 'Payments are settled via our Cashu mint: %1$s', 'cashu-for-woocommerce' ),
				'<strong>' . esc_html( $trusted_mint ) . '</strong>'
			);
			?>
		</div>
		<?php
	}

	/**
	 * Show change if the order was a Cashu one.
	 *
	 * @codeCoverageIgnore Pure markup; verified by eye in wp-env.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$this->render_change_section();
	}

	/**
	 * Renders the change
	 *
	 * @codeCoverageIgnore Pure markup; verified by eye in wp-env.
	 */
	public function render_change_section() {
		// Same once-per-request guard as receipt_page — this renders via
		// both the thank-you hook and woocommerce_after_my_account, and a
		// double-firing template must not emit two #cashu-change-root ids.
		if ( $this->change_rendered ) {
			return;
		}
		$this->change_rendered = true;

		wp_enqueue_style( 'cashu-public' );
		wp_enqueue_script( 'cashu-thanks' );

		echo '<div id="cashu-change-root"></div>';
	}

	public function is_available(): bool {
		// Global LN address set
		$lightning_address = trim( (string) get_option( 'cashu_lightning_address', '' ) );
		if ( '' === $lightning_address ) {
			return false;
		}

		// Global trusted mint set
		$trusted_mint = trim( (string) get_option( 'cashu_trusted_mint', '' ) );
		if ( '' === $trusted_mint ) {
			return false;
		}

		// At least one payment path enabled. Belt-and-braces — the validator
		// already prevents saving zero paths, but a corrupted option or third-
		// party filter shouldn't 500 the checkout.
		$paths = CashuPaths::sanitize( get_option( 'cashu_paths', CashuPaths::DEFAULT_PATHS ) );
		if ( ! CashuPaths::any_enabled( $paths ) ) {
			return false;
		}

		// This Gateway enabled (Settings > Payments)
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		// Hide the option when the cart total falls outside the mint's /
		// LNURL's advertised bolt11 amount limits — better than letting the
		// customer pick it and fail at quote time. Fail open on any
		// uncertainty (no cart, no rate, stale snapshot): only fresh limits
		// data that clearly excludes the amount may hide the gateway.
		if ( ! is_admin() && function_exists( 'WC' ) && null !== WC()->cart ) {
			try {
				$total = (float) WC()->cart->get_total( 'edit' );
				if ( $total > 0 ) {
					$quote = CashuHelper::fiatToSats( $total, get_woocommerce_currency() );
					$sats  = absint( $quote['sats'] ?? 0 );
					if ( $sats > 0 && ! MintLimits::allows( $sats ) ) {
						return false;
					}
				}
			} catch ( \Throwable $e ) {
				// Rate lookup failed — can't judge the amount; show the gateway.
				Logger::debug( 'is_available limit check skipped: ' . $e->getMessage() );
			}
		}

		return true;
	}
}
