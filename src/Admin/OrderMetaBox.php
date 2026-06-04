<?php

declare(strict_types=1);

namespace Cashu\WC\Admin;

use WC_Order;

/**
 * "Cashu Payment" meta-box on the WC order edit screen for orders paying
 * via the cashu_default gateway. Lets the admin open the customer's
 * receipt page in one click — the page-load watcher in checkout.ts auto-
 * recovers any paid-but-unclaimed mint quote there, so opening the link
 * IS the recovery action.
 *
 * Also surfaces the active mint quote id (and any archived ones) for
 * forensic / out-of-band lookup against the mint.
 */
final class OrderMetaBox {

	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ) );
	}

	public static function add(): void {
		$screen = function_exists( 'wc_get_page_screen_id' )
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'cashu_payment',
			__( 'Cashu Payment', 'cashu-for-woocommerce' ),
			array( self::class, 'render' ),
			$screen,
			'side',
			'default'
		);
	}

	public static function render( $post_or_order ): void {
		$order = ( $post_or_order instanceof WC_Order )
			? $post_or_order
			: wc_get_order( $post_or_order );

		if ( ! $order || $order->get_payment_method() !== 'cashu_default' ) {
			echo '<p>' . esc_html__( 'Not a Cashu order.', 'cashu-for-woocommerce' ) . '</p>';
			return;
		}

		$mint             = (string) $order->get_meta( '_cashu_melt_mint', true );
		$mint_quote_id    = (string) $order->get_meta( '_cashu_mint_quote_id', true );
		$melt_quote_id    = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		$payment_url      = $order->get_checkout_payment_url();
		$mint_archive_raw = (string) $order->get_meta( '_cashu_archived_mint_quotes', true );
		$mint_archive     = '' !== $mint_archive_raw
			? (array) json_decode( $mint_archive_raw, true )
			: array();
		$melt_archive_raw = (string) $order->get_meta( '_cashu_archived_melt_quotes', true );
		$melt_archive     = '' !== $melt_archive_raw
			? (array) json_decode( $melt_archive_raw, true )
			: array();

		echo '<p style="margin:0 0 10px;">';
		if ( $order->is_paid() ) {
			echo '<span style="color:#2f8f3a;font-weight:600;">' . esc_html__( 'Paid.', 'cashu-for-woocommerce' ) . '</span> ';
			echo esc_html__( 'Open the receipt page to verify or run recovery.', 'cashu-for-woocommerce' );
		} else {
			echo esc_html__( 'Open the customer\'s receipt page in a new tab. If the payment settled at the mint but never claimed (e.g. the customer\'s browser died mid-payment), the page-load watcher will mint the proofs and finalize the order.', 'cashu-for-woocommerce' );
		}
		echo '</p>';

		echo '<p style="margin:0 0 12px;"><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url( $payment_url ) . '">';
		echo esc_html__( 'Open customer payment page', 'cashu-for-woocommerce' );
		echo '</a></p>';

		if ( '' !== $mint ) {
			echo '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Mint', 'cashu-for-woocommerce' ) . '</strong></p>';
			echo '<p style="margin:0 0 10px;word-break:break-all;font-family:monospace;font-size:11px;">' . esc_html( $mint ) . '</p>';
		}

		if ( '' !== $mint_quote_id ) {
			echo '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Mint quote (customer payment)', 'cashu-for-woocommerce' ) . '</strong></p>';
			echo '<p style="margin:0 0 10px;word-break:break-all;font-family:monospace;font-size:11px;">' . esc_html( $mint_quote_id ) . '</p>';
		}

		if ( '' !== $melt_quote_id ) {
			echo '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Melt quote (vendor payment)', 'cashu-for-woocommerce' ) . '</strong></p>';
			echo '<p style="margin:0 0 10px;word-break:break-all;font-family:monospace;font-size:11px;">' . esc_html( $melt_quote_id ) . '</p>';
		}

		if ( ! empty( $mint_archive ) ) {
			echo '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Archived mint quotes', 'cashu-for-woocommerce' ) . '</strong></p>';
			echo '<p style="margin:0 0 6px;font-size:11px;color:#666;">' . esc_html__( 'Previous customer-payment quotes for this order, kept for out-of-band recovery if a customer paid one before rotation.', 'cashu-for-woocommerce' ) . '</p>';
			foreach ( $mint_archive as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['quote'] ) ) {
					continue;
				}
				echo '<p style="margin:0 0 8px;word-break:break-all;font-family:monospace;font-size:11px;">' . esc_html( (string) $entry['quote'] );
				if ( ! empty( $entry['amount'] ) ) {
					echo ' <span style="color:#666;">(' . esc_html( (string) $entry['amount'] ) . ' sat)</span>';
				}
				echo '</p>';
			}
		}

		if ( ! empty( $melt_archive ) ) {
			echo '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Archived melt quotes', 'cashu-for-woocommerce' ) . '</strong></p>';
			echo '<p style="margin:0 0 6px;font-size:11px;color:#666;">' . esc_html__( 'Previous vendor-payout quotes for this order, with the underlying BOLT11 invoice for forensic lookup against the mint.', 'cashu-for-woocommerce' ) . '</p>';
			foreach ( $melt_archive as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['quote'] ) ) {
					continue;
				}
				echo '<p style="margin:0 0 4px;word-break:break-all;font-family:monospace;font-size:11px;">' . esc_html( (string) $entry['quote'] );
				if ( ! empty( $entry['amount'] ) ) {
					echo ' <span style="color:#666;">(' . esc_html( (string) $entry['amount'] ) . ' sat)</span>';
				}
				echo '</p>';
				if ( ! empty( $entry['request'] ) ) {
					echo '<details style="margin:0 0 8px;"><summary style="font-size:11px;color:#666;cursor:pointer;">' . esc_html__( 'BOLT11 invoice', 'cashu-for-woocommerce' ) . '</summary>';
					echo '<p style="margin:4px 0 0;word-break:break-all;font-family:monospace;font-size:10px;">' . esc_html( (string) $entry['request'] ) . '</p>';
					echo '</details>';
				}
			}
		}
	}
}
