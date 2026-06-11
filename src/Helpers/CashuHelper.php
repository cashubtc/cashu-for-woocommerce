<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * Helper for basic Cashu-related utilities.
 *
 * Right now we only use this for fiat→sats conversion when creating
 * Lightning invoices server-side.
 */
class CashuHelper {
	/**
	 * Stored Lightning address (for future use / convenience).
	 */
	private string $lightningAddress;

	/**
	 * Whether modal checkout is enabled (for future use).
	 */
	private bool $modal;

	public function __construct( ?string $lightningAddress = null ) {
		$this->lightningAddress =
		trim( $lightningAddress ) ?? (string) get_option( 'cashu_lightning_address', '' );
		$this->modal            = 'yes' === get_option( 'cashu_modal_checkout', 'yes' );
	}

	public function isConfigured(): bool {
		return '' !== $this->lightningAddress;
	}

	/**
	 * Convert a fiat amount to sats and return a full quote bundle (price + timestamp + source).
	 *
	 * @param float  $amount amount in fiat
	 * @param string $fiat   ISO 4217 code, eg "USD", "GBP", "EUR"
	 *
	 * @return array{
	 *   amount_fiat: float,
	 *   fiat: string,
	 *   btc_price: float,
	 *   sats: int,
	 *   source: string,
	 *   quoted_at: int
	 * }
	 *
	 * @throws \RuntimeException when both API calls fail or return bad data
	 */
	public static function fiatToSats( float $amount, string $fiat = 'USD' ): array {
		$fiat_upper = strtoupper( trim( $fiat ) );
		$fiat_lower = strtolower( $fiat_upper );
		$quoted_at  = time();

		if ( $amount <= 0 ) {
			return array(
				'amount_fiat' => (float) $amount,
				'fiat'        => $fiat_upper,
				'btc_price'   => 0.0,
				'sats'        => 0,
				'source'      => 'none',
				'quoted_at'   => $quoted_at,
			);
		}

		$source = '';
		try {
			$btc_price = self::get_btc_price_fiat_coinbase_spot( $fiat_upper );
			$source    = 'coinbase_spot';
		} catch ( \RuntimeException $e ) {
			// Fallback to CoinGecko if Coinbase fails.
			$btc_price = self::get_btc_price_fiat_coingecko( $fiat_lower );
			$source    = 'coingecko_simple_price';
		}

		if ( $btc_price <= 0 ) {
			throw new \RuntimeException( 'Invalid BTC price (non positive).' );
		}

		// Return amount in sats, rounded up, min zero
		$sats = (int) ceil( ( $amount / $btc_price ) * 100000000 );
		$sats = max( 0, $sats );

		return array(
			'amount_fiat' => (float) $amount,
			'fiat'        => $fiat_upper,
			'btc_price'   => (float) $btc_price,
			'sats'        => $sats,
			'source'      => $source,
			'quoted_at'   => $quoted_at,
		);
	}

	/**
	 * Fetch BTC price in fiat from Coinbase spot endpoint, with short caching.
	 *
	 * @param string $fiat_upper e.g. "GBP"
	 */
	private static function get_btc_price_fiat_coinbase_spot( string $fiat_upper ): float {
		$cache_key = 'cashu_btc_spot_cb_' . strtolower( $fiat_upper );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_numeric( $cached ) && (float) $cached > 0 ) {
			return (float) $cached;
		}

		$url      = sprintf( 'https://api.coinbase.com/v2/prices/BTC-%s/spot', rawurlencode( $fiat_upper ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Failed to fetch BTC price from Coinbase.' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new \RuntimeException( 'Coinbase returned HTTP ' . absint( $code ) . ' for spot price.' );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		Logger::debug( 'Coinbase spot response: ' . wp_json_encode( $data ) );

		if ( ! isset( $data['data']['amount'], $data['data']['currency'] ) ) {
			throw new \RuntimeException( 'Invalid Coinbase spot response.' );
		}

		if ( strtoupper( (string) $data['data']['currency'] ) !== $fiat_upper ) {
			throw new \RuntimeException( 'Coinbase spot currency mismatch.' );
		}

		$btc_price = (float) $data['data']['amount'];
		if ( $btc_price <= 0 ) {
			throw new \RuntimeException( 'Invalid Coinbase spot amount.' );
		}

		// Cache briefly, enough to protect upstream during bursts.
		set_transient( $cache_key, $btc_price, 30 );

		return $btc_price;
	}

	/**
	 * Fetch BTC price in fiat from CoinGecko simple/price endpoint, with short caching.
	 *
	 * @param string $fiat_lower e.g. "gbp"
	 */
	private static function get_btc_price_fiat_coingecko( string $fiat_lower ): float {
		$cache_key = 'cashu_btc_spot_cg_' . $fiat_lower;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_numeric( $cached ) && (float) $cached > 0 ) {
			return (float) $cached;
		}

		$url = add_query_arg(
			array(
				'ids'           => 'bitcoin',
				'vs_currencies' => $fiat_lower,
			),
			'https://api.coingecko.com/api/v3/simple/price'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Failed to fetch BTC price from CoinGecko.' );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r
		Logger::debug( 'CoinGecko response: ' . print_r( $data, true ) );

		if ( ! isset( $data['bitcoin'][ $fiat_lower ] ) || (float) $data['bitcoin'][ $fiat_lower ] <= 0 ) {
			throw new \RuntimeException( 'Invalid CoinGecko price response.' );
		}

		$btc_price = (float) $data['bitcoin'][ $fiat_lower ];

		set_transient( $cache_key, $btc_price, 30 );

		return $btc_price;
	}

	/**
	 * Truncate a payment preimage for display in order notes: first/last 8
	 * hex chars. The preimage is proof-of-payment — anyone who reads it can
	 * replay a settlement claim — and order notes are visible to every shop
	 * manager and to notes-export plugins. The full value stays in the
	 * `_cashu_payment_preimage` order meta for forensic use.
	 */
	public static function redactPreimage( ?string $preimage ): string {
		$preimage = (string) $preimage;
		if ( strlen( $preimage ) <= 16 ) {
			return $preimage;
		}
		return substr( $preimage, 0, 8 ) . '…' . substr( $preimage, -8 );
	}

	public static function getConfig(): array {
		$ln_address   = get_option( 'cashu_lightning_address' );
		$trusted_mint = get_option( 'cashu_trusted_mint' );
		$debug        = get_option( 'cashu_debug' );

		if ( ! $ln_address || ! $trusted_mint ) {
			return array();
		}

		return array(
			'ln_address'   => $ln_address,
			'trusted_mint' => $trusted_mint,
			'debug'        => $debug,
		);
	}
}
