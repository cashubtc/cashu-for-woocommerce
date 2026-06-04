<?php

declare(strict_types=1);

namespace Cashu\WC\Gateway;

use Automattic\WooCommerce\Enums\OrderStatus;
use Cashu\WC\Helpers\Bolt11;
use Cashu\WC\Helpers\CashuHelper;
use Cashu\WC\Helpers\Logger;
use Cashu\WC\Helpers\LightningAddress;
use Cashu\WC\Helpers\PayController;
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

	public function __construct() {
		// Init gateway
		$this->id                 = 'cashu_default';
		$this->icon               = CASHU_WC_URL . 'assets/images/cashu-logo.png';
		$this->method_title       = __( 'Cashu ecash', 'cashu-for-woocommerce' );
		$this->method_description = __(
			'Accept Cashu tokens and melt them straight to your Bitcoin lightning address.',
			'cashu-for-woocommerce'
		);
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->init_form_fields();

		$this->title        = $this->get_option( 'title', 'Cashu ecash' );
		$this->description  = $this->get_option(
			'description',
			__( 'Scan the QR code with a Lightning or Cashu wallet to pay.', 'cashu-for-woocommerce' )
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
				'default' => __( 'Cashu ecash', 'cashu-for-woocommerce' ),
			),
			'description' => array(
				'title'   => __( 'Checkout instructions', 'cashu-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __(
					'You will be able to complete your purchase using Cashu ecash.',
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
	 * Limit size of default icon
	 */
	public function get_icon() {
		$icon = parent::get_icon();
		return str_replace( 'src=', 'style="max-width:40px;" src=', $icon );
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
		// QR Code
		wp_register_script(
			'cashu-qrcode',
			CASHU_WC_URL . 'assets/js/frontend/qrcode.min.js',
			array( 'jquery' ),
			$this->asset_version( 'assets/js/frontend/qrcode.min.js' ),
			false // head
		);

		// Main checkout
		wp_register_script(
			'cashu-checkout',
			CASHU_WC_URL . 'assets/js/cashu/checkout.js',
			array( 'jquery', 'cashu-qrcode', 'wp-i18n' ),
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

				'i18n'          => array(
					// General / bootstrap
					'data_incomplete'      => __( 'Payment data incomplete, please refresh and try again.', 'cashu-for-woocommerce' ),
					'invoice_failed'       => __( 'Could not prepare the invoice, please refresh and try again', 'cashu-for-woocommerce' ),

					// Status
					'payment_in_progress'  => __( 'Payment already in progress', 'cashu-for-woocommerce' ),

					// QR interactions
					'copied'               => __( 'Copied!', 'cashu-for-woocommerce' ),
					'waiting_for_payment'  => __( 'Waiting for payment...', 'cashu-for-woocommerce' ),
					'connecting_to_mint'   => __( 'Connecting to mint...', 'cashu-for-woocommerce' ),

					// Tabs
					'tab_unified'          => __( 'Auto', 'cashu-for-woocommerce' ),
					'tab_cashu'            => __( 'Cashu', 'cashu-for-woocommerce' ),
					'tab_lightning'        => __( 'Lightning', 'cashu-for-woocommerce' ),

					// Recovery flow (Lightning leg)
					'payment_failed'       => __( 'Payment failed. Please copy the recovery token below.', 'cashu-for-woocommerce' ),
					'waiting_confirmation' => __( 'Waiting for payment confirmation...', 'cashu-for-woocommerce' ),

					// Lightning leg progress
					'payment_received'     => __( 'Payment received by our mint...', 'cashu-for-woocommerce' ),
					'paying_invoice'       => __( 'Paying invoice...', 'cashu-for-woocommerce' ),
					'confirming_payment'   => __( 'Confirming payment...', 'cashu-for-woocommerce' ),

					// Change
					'change_from_network'  => __( 'Change From Network Fee Reserve', 'cashu-for-woocommerce' ),
					'change_from_token'    => __( 'Change From Your Token', 'cashu-for-woocommerce' ),

					// Order status polling
					'invoice_expired'      => __( 'Invoice has expired', 'cashu-for-woocommerce' ),

					/* translators: 1: time remaining, formatted like MM:SS */
					'invoice_expires_in'   => __( 'Invoice expires in: %1$s', 'cashu-for-woocommerce' ),
				),
			)
		);

		// Change box
		wp_register_script(
			'cashu-thanks',
			CASHU_WC_URL . 'assets/js/frontend/thanks.js',
			array( 'wp-i18n' ),
			CASHU_WC_VERSION,
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
						'If you use a Cashu wallet, copy any change tokens below and paste them into your wallet.',
						'cashu-for-woocommerce'
					),

					/* translators: %s is the word "Important:" (label) shown before the message */
					'important'   => __( 'Important:', 'cashu-for-woocommerce' ),

					'tip'         => __(
						'save your change now, we do not store tokens on our server.',
						'cashu-for-woocommerce'
					),

					'dust_badge'  => __( 'Dust', 'cashu-for-woocommerce' ),

					'dust_note'   => __(
						'May be too small to spend on its own due to per proof fees.',
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
			CASHU_WC_VERSION
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
			} catch ( \Error $e ) {
				Logger::error( 'Could not setup Cashu payment: ' . $e->getMessage() );
				wc_add_notice( __( 'Cashu payment setup failed, please try again.', 'cashu-for-woocommerce' ), 'error' );
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
		/**
		 * Filter the order status for cashu payment (Default: 'pending').
		 *
		 * @since 3.6.0
		 *
		 * @param string $status The default status.
		 * @param object $order  The order object.
		 */
		$process_payment_status = apply_filters(
			'woocommerce_cashu_process_payment_order_status',
			OrderStatus::PENDING,
			$order
		);

		// Set order status.
		$order->update_status(
			$process_payment_status,
			_x( 'Awaiting Cashu payment', 'Cashu payment method', 'cashu-for-woocommerce' )
		);

		// Determine invoice amount in sats (merchant receives this).
		$order_total_sats = $this->get_total_sats( $order );

		// Create or reuse melt quote (vendor payment side), store fee reserve,
		// set the headline _cashu_melt_total = amount + fee_reserve + input buffer.
		$this->ensure_melt_quote_for_order( $order, $order_total_sats );

		// Create or reuse mint quote (customer payment side). The customer pays
		// this BOLT11 via LN; the quote_id is what the browser uses to claim
		// proofs from the mint. Locking this server-side means a page reload
		// or browser switch can never lose the quote_id and orphan the
		// customer's payment.
		$melt_total = absint( $order->get_meta( '_cashu_melt_total', true ) );
		$this->ensure_mint_quote_for_order( $order, $melt_total );

		$order->save();
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

		// Convert order total to sats
		$total = (float) $order->get_total();
		$quote = CashuHelper::fiatToSats( $total, $order->get_currency() );

		$order_total_sats = absint( $quote['sats'] ?? 0 );
		if ( $order_total_sats <= 0 ) {
			Logger::error( 'Cashu quote failed, sats amount is invalid.' );
			throw new \RuntimeException( 'Could not get price quote in bitcoin.' );
		}

		// Set order meta
		$order->update_meta_data( '_cashu_spot_total', $order_total_sats );
		$order->update_meta_data( '_cashu_spot_time', $quote['quoted_at'] );
		$order->update_meta_data( '_cashu_spot_btc', $quote['btc_price'] );
		$order->update_meta_data( '_cashu_spot_source', $quote['source'] );

		// Remove any old melt quotes. Mint quote meta is intentionally NOT cleared
		// here — once a mint quote BOLT11 has been issued to the customer, we must
		// preserve it (potentially in archive) so a paid quote can always be linked
		// back to the order. ensure_mint_quote_for_order handles archival when it
		// genuinely needs to rotate.
		$order->delete_meta_data( '_cashu_melt_quote_id' );
		$order->delete_meta_data( '_cashu_melt_quote_expiry' );
		$order->delete_meta_data( '_cashu_melt_total' );
		$order->delete_meta_data( '_cashu_melt_mint' );
		$order->delete_meta_data( '_cashu_payment_hash' );

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

		// Use existing melt quote?
		$quote_id     = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		$quote_expiry = absint( $order->get_meta( '_cashu_melt_quote_expiry', true ) );
		$melt_mint    = (string) $order->get_meta( '_cashu_melt_mint', true );
		if ( '' !== $quote_id
			&& $quote_expiry > time() + self::QUOTE_EXPIRY_SECS
			&& $this->trusted_mint === $melt_mint
		) {
			return;
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
		$quote       = $this->request_melt_quote_bolt11( $invoice );
		$quote_id    = sanitize_text_field( (string) ( $quote['quote'] ?? '' ) );
		$expiry      = absint( $quote['expiry'] ?? 0 );
		$amount      = absint( $quote['amount'] ?? 0 );
		$fee_reserve = absint( $quote['fee_reserve'] ?? 0 );
		$unit        = (string) ( $quote['unit'] ?? '' );

		if ( '' === $quote_id || $amount <= 0 || $expiry <= 0 || 'sat' !== $unit ) {
			throw new \RuntimeException( 'Invalid melt quote response from mint.' );
		}

		// Persist the quote context for confirm step later.
		$order->update_meta_data( '_cashu_melt_quote_id', $quote_id );
		$order->update_meta_data( '_cashu_melt_quote_expiry', $expiry );
		$order->update_meta_data( '_cashu_melt_mint', $this->trusted_mint );

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

		if ( '' !== $existing_id && $existing_amount === $amount_sats ) {
			// Keep the existing quote if it hasn't fully expired at the mint.
			// expiry == 0 means the mint doesn't advertise an expiry; treat as
			// "still valid" — the cashu spec allows null/missing.
			if ( 0 === $existing_expiry || $existing_expiry > time() ) {
				return;
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
				'amount'  => $existing_amount,
				'expiry'  => $existing_expiry,
			);
			$order->update_meta_data( '_cashu_archived_mint_quotes', (string) wp_json_encode( $archive ) );
		}

		$quote = $this->request_mint_quote_bolt11( $amount_sats );

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

	private function request_mint_quote_bolt11( int $amount_sats ): array {
		$endpoint = rtrim( $this->trusted_mint, '/' ) . '/v1/mint/quote/bolt11';
		$args     = array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'amount' => $amount_sats,
					'unit'   => 'sat',
				)
			),
		);

		$res = wp_remote_post( $endpoint, $args );
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( 'Mint quote request failed: ' . esc_html( sanitize_text_field( $res->get_error_message() ) ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( 'Mint quote request failed, HTTP ' . esc_html( (string) $code ) );
		}

		$body = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			throw new \RuntimeException( 'Mint quote response is not JSON.' );
		}

		return $json;
	}

	/**
	 * Execute a NUT-05 melt: hand the mint a set of input proofs against an existing
	 * melt quote so it pays the underlying lightning invoice and (optionally) returns
	 * change proofs.
	 *
	 * @param string $quote_id The mint's melt quote id (stored on the order).
	 * @param array  $proofs   Array of proofs as decoded from the wallet's POST body.
	 *                         Each proof must contain id, amount, secret, C; witness optional.
	 *
	 * @throws \RuntimeException on transport or mint error.
	 */
	public function request_melt_bolt11( string $quote_id, array $proofs ): array {
		$endpoint = rtrim( $this->trusted_mint, '/' ) . '/v1/melt/bolt11';

		// Coerce amounts to int — wallets may emit decimal-string or numeric per NUT-18.
		$inputs = array_map(
			static function ( $p ) {
				$proof = array(
					'id'     => (string) ( $p['id'] ?? '' ),
					'amount' => (int) ( is_numeric( $p['amount'] ?? null ) ? $p['amount'] : 0 ),
					'secret' => (string) ( $p['secret'] ?? '' ),
					'C'      => (string) ( $p['C'] ?? '' ),
				);
				if ( isset( $p['witness'] ) && '' !== $p['witness'] ) {
					$proof['witness'] = $p['witness'];
				}
				return $proof;
			},
			$proofs
		);

		$args = array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'quote'  => $quote_id,
					'inputs' => $inputs,
				)
			),
		);

		$res = wp_remote_post( $endpoint, $args );
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( 'Mint melt request failed: ' . esc_html( sanitize_text_field( $res->get_error_message() ) ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( 'Mint melt request failed, HTTP ' . esc_html( (string) $code ) . ': ' . esc_html( $body ) );
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			throw new \RuntimeException( 'Mint melt response is not JSON.' );
		}

		return $json;
	}

	private function request_melt_quote_bolt11( string $bolt11 ): array {
		// Setup request
		$endpoint = $this->trusted_mint . '/v1/melt/quote/bolt11';
		$args     = array(
			'timeout' => 10,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'request' => $bolt11,
					'unit'    => 'sat',
				)
			),
		);

		// Make request
		$res = wp_remote_post( $endpoint, $args );
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( 'Mint quote request failed: ' . esc_html( sanitize_text_field( $res->get_error_message() ) ) );
		}

		// Check response code is 2xx (OK)
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( 'Mint quote request failed, HTTP ' . esc_html( (string) $code ) );
		}

		// Decode response body
		$body = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			throw new \RuntimeException( 'Mint quote response is not JSON.' );
		}

		return $json;
	}

	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'cashu-for-woocommerce' ) . '</p>';
			return;
		}

		// Check payment is for our gateway
		if ( $order->get_payment_method() !== $this->id ) {
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
		if ( $spot_expiry < time()
			|| $quote_expiry < $spot_expiry
			|| '' === $mint_quote_id
		) {
			try {
				$this->setup_cashu_payment( $order );
				// Reset spot expiry
				$spot_time   = absint( $order->get_meta( '_cashu_spot_time', true ) );
				$spot_expiry = $spot_time + self::QUOTE_EXPIRY_SECS;
			} catch ( \Error $e ) {
				Logger::error( 'Could not setup Cashu payment on receipt page: ' . $e->getMessage() );
				wc_add_notice( __( 'Cashu payment setup failed, please try again.', 'cashu-for-woocommerce' ), 'error' );
			}
		}

		$melt_total         = absint( $order->get_meta( '_cashu_melt_total', true ) );
		$quote_id           = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		$trusted_mint       = (string) $order->get_meta( '_cashu_melt_mint', true );
		$mint_quote_id      = (string) $order->get_meta( '_cashu_mint_quote_id', true );
		$mint_quote_request = (string) $order->get_meta( '_cashu_mint_quote_request', true );
		$mint_quote_amount  = absint( $order->get_meta( '_cashu_mint_quote_amount', true ) );
		$mint_quote_expiry  = absint( $order->get_meta( '_cashu_mint_quote_expiry', true ) );

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

		wp_enqueue_script( 'cashu-qrcode' );
		wp_enqueue_script( 'cashu-checkout' );
		wp_enqueue_style( 'cashu-public' );

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

				<div class="cashu-tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Payment options', 'cashu-for-woocommerce' ); ?>">
					<button
						type="button"
						class="cashu-tab is-active"
						role="tab"
						aria-selected="true"
						data-cashu-tab="unified"
					><?php esc_html_e( 'Auto', 'cashu-for-woocommerce' ); ?></button>
					<button
						type="button"
						class="cashu-tab"
						role="tab"
						aria-selected="false"
						data-cashu-tab="cashu"
					><?php esc_html_e( 'Cashu', 'cashu-for-woocommerce' ); ?></button>
					<button
						type="button"
						class="cashu-tab"
						role="tab"
						aria-selected="false"
						data-cashu-tab="lightning"
					><?php esc_html_e( 'Lightning', 'cashu-for-woocommerce' ); ?></button>
				</div>

				<div class="cashu-qr-wrap">
					<div class="cashu-qr" data-cashu-qr>
						<!-- JS renders QR here, canvas or img is fine -->
					</div>

					<div class="cashu-qr-icon" aria-hidden="true">
						<img src="<?php echo esc_url( $this->icon ); ?>" alt="">
					</div>
				</div>

				<div class="cashu-recovery" data-cashu-recovery hidden>
					<div class="cashu-recovery-label">
						<?php esc_html_e( 'Copy the token below and paste it into a Cashu wallet to reclaim your sats.', 'cashu-for-woocommerce' ); ?>
					</div>
					<button type="button" class="cashu-recovery-copy" data-cashu-recovery-copy>
						<?php esc_html_e( 'Copy recovery token', 'cashu-for-woocommerce' ); ?>
					</button>
				</div>

				<div class="cashu-feenote">
					<?php
					printf(
						/* translators: %1$s: Mint hostname */
						esc_html__( 'Payments are settled at our mint: %1$s', 'cashu-for-woocommerce' ),
						'<strong>' . esc_html( $trusted_mint ) . '</strong>'
					);
					?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Show change if the order was a Cashu one.
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
	 */
	public function render_change_section() {
		wp_enqueue_style( 'cashu-public' );
		wp_enqueue_script( 'cashu-thanks' );

		echo '<div id="cashu-change-root"></div>';
	}

	public function is_available(): bool {
		// Cashu payment provider enabled
		$enabled = get_option( 'cashu_enabled', 'no' );
		if ( 'yes' !== $enabled ) {
			return false;
		}

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

		// This Gateway enabled
		return 'yes' === $this->enabled;
	}
}
