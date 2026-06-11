<?php
declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * Helper for resolving Lightning addresses to BOLT11 invoices.
 */
class LightningAddress {
	/**
	 * Get a BOLT11 invoice for the given Lightning address and amount in sats.
	 *
	 * If $address already looks like a BOLT11 invoice (lnbc* / lntb*), it is returned as-is.
	 *
	 * @param string $address     Lightning address eg "you@example.com" or a BOLT11 invoice.
	 * @param int    $amount_sats amount in sats
	 * @param string|null $comment Optional comment (if supported by LN address)
	 *
	 * @throws \RuntimeException when the address is invalid or the LNURL flow fails
	 */
	public static function get_invoice( string $address, int $amount_sats, ?string $comment = null ): string {
		$address = trim( $address );

		// If it already looks like a BOLT11, just return it.
		if (
			0 === stripos( $address, 'lnbc' ) || // mainnet
			0 === stripos( $address, 'lntb' ) || // testnet
			0 === stripos( $address, 'lnbcrt' )  // regtest (local)
		) {
			return $address;
		}

		if ( false === strpos( $address, '@' ) ) {
			throw new \RuntimeException( 'Invalid Lightning address.' );
		}

		[ $name, $host ] = explode( '@', $address, 2 );

		$lnurlp_url = sprintf(
			'https://%s/.well-known/lnurlp/%s',
			$host,
			rawurlencode( $name )
		);

		$meta_response = wp_remote_get( $lnurlp_url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $meta_response ) ) {
			throw new \RuntimeException( 'Failed to fetch LNURL metadata.' );
		}

		$meta_code = wp_remote_retrieve_response_code( $meta_response );
		$meta_body = json_decode( wp_remote_retrieve_body( $meta_response ), true );

		if ( 200 !== $meta_code || ! is_array( $meta_body ) || empty( $meta_body['callback'] ) ) {
			throw new \RuntimeException( 'Invalid LNURL metadata response.' );
		}

		$amount_msat = $amount_sats * 1000;

		// LUD-06 sendable bounds: reject here rather than letting the
		// callback fail opaquely. Only positive numeric bounds constrain —
		// 0 / absent / junk must not be read as "max 0".
		$min_msat = is_numeric( $meta_body['minSendable'] ?? null ) ? (int) $meta_body['minSendable'] : 0;
		$max_msat = is_numeric( $meta_body['maxSendable'] ?? null ) ? (int) $meta_body['maxSendable'] : 0;
		if ( ( $min_msat > 0 && $amount_msat < $min_msat ) || ( $max_msat > 0 && $amount_msat > $max_msat ) ) {
			throw new AmountLimitException(
				sprintf(
					'Lightning address amount outside limits: %d sat is not within %d-%d msat sendable bounds.',
					esc_html( (string) $amount_sats ),
					esc_html( (string) $min_msat ),
					esc_html( (string) $max_msat )
				)
			);
		}

		$query_args = array(
			'amount' => $amount_msat,
		);

		$comment_allowed = isset( $meta_body['commentAllowed'] ) ? (int) $meta_body['commentAllowed'] : 0;

		if ( null !== $comment ) {
			$comment = trim( $comment );

			if ( '' !== $comment && $comment_allowed > 0 ) {
				// Enforce max length, safest behaviour is truncate.
				if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
					if ( mb_strlen( $comment, 'UTF-8' ) > $comment_allowed ) {
						$comment = mb_substr( $comment, 0, $comment_allowed, 'UTF-8' );
					}
				} elseif ( strlen( $comment ) > $comment_allowed ) {
						$comment = substr( $comment, 0, $comment_allowed );
				}

				$query_args['comment'] = $comment;
			}
		}

		$query       = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
		$invoice_url = $meta_body['callback']
			. ( str_contains( $meta_body['callback'], '?' ) ? '&' : '?' )
			. $query;

		$inv_response = wp_remote_get( $invoice_url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $inv_response ) ) {
			throw new \RuntimeException( 'Failed to request invoice.' );
		}

		$inv_code = wp_remote_retrieve_response_code( $inv_response );
		$inv_body = json_decode( wp_remote_retrieve_body( $inv_response ), true );

		if ( 200 !== $inv_code || empty( $inv_body['pr'] ) ) {
			// LUD-06 error responses carry a human-readable `reason`; keep it
			// for the log so "amount too small" is distinguishable from a
			// provider outage.
			$reason = is_array( $inv_body ) ? sanitize_text_field( (string) ( $inv_body['reason'] ?? '' ) ) : '';
			throw new \RuntimeException( '' !== $reason ? 'Invalid invoice response: ' . esc_html( $reason ) : 'Invalid invoice response.' );
		}

		return $inv_body['pr'];
	}
}
