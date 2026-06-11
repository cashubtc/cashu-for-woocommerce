<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * HTTP client for a Cashu mint's NUT-04 (mint) and NUT-05 (melt) bolt11
 * endpoints. Stateless: every method takes the mint URL explicitly, so a
 * caller with an order in hand passes the order's stored mint and an
 * admin-side settings change can never route a request to a host that
 * doesn't know the quote.
 */
final class MintClient {

	/**
	 * Cache TTL for a successful melt-quote state probe. A polling browser
	 * at 5-s cadence will short-circuit most polls; the worst-case
	 * mint-PAID-to-browser-redirect latency is one TTL window. Kept well
	 * above the poll cadence because several mints return HTTP 429 on
	 * tight probe loops.
	 */
	public const MELT_STATE_FRESH_TTL = MINUTE_IN_SECONDS;

	/**
	 * Cache TTL for a melt-quote state probe that returned an empty /
	 * non-200 response (HTTP 429, network blip, mint unreachable). Longer
	 * than the fresh TTL so a clearly-rate-limited mint isn't hammered
	 * every poll cycle. The pending marker is preserved either way;
	 * MeltReconciler and a later un-rate-limited probe will still flip the
	 * order PAID. Worst-case additional latency is one empty-TTL window
	 * past the mint recovering.
	 */
	public const MELT_STATE_EMPTY_TTL = 2 * MINUTE_IN_SECONDS;

	/**
	 * Canonical form of a mint URL for equality comparisons: scheme + host
	 * lowercased, default ports elided, IPv6 brackets preserved, path's
	 * trailing `/` trimmed (matching the `URL.origin + pathname` shape the
	 * client-side sameMint() uses). Falls back to case-fold + rtrim when
	 * parsing fails so a slightly-malformed URL still produces a stable key.
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return strtolower( rtrim( $url, '/' ) );
		}
		$scheme       = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		$host         = strtolower( (string) $parts['host'] );
		$port         = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$path         = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$default_port = ( 'https' === $scheme ) ? 443 : ( ( 'http' === $scheme ) ? 80 : 0 );
		$port_str     = ( $port > 0 && $port !== $default_port ) ? ':' . $port : '';
		return $scheme . '://' . $host . $port_str . $path;
	}

	/**
	 * Best-effort fetch of a NUT-05 melt-quote state. Never throws; an empty
	 * return means "unknown" and callers should err on the side of
	 * preserving the existing quote.
	 */
	public static function melt_quote_state( string $mint_url, string $quote_id ): array {
		if ( '' === $mint_url ) {
			return array();
		}
		$url = rtrim( $mint_url, '/' ) . '/v1/melt/quote/bolt11/' . rawurlencode( $quote_id );
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			Logger::debug( 'Melt quote state lookup failed: ' . $res->get_error_message() );
			return array();
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			Logger::debug( 'Melt quote state lookup HTTP ' . $code );
			return array();
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Cached variant of melt_quote_state(). One transient per quote id,
	 * shared by every probing path (polling endpoint, quote-rotation
	 * guard) so concurrent browser polls and page refreshes pay for one
	 * mint hit per TTL window between them.
	 */
	public static function melt_quote_state_cached( string $mint_url, string $quote_id ): array {
		$cache_key = 'cashu_melt_state_' . md5( $quote_id );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$state = self::melt_quote_state( $mint_url, $quote_id );
		$ttl   = empty( $state ) ? self::MELT_STATE_EMPTY_TTL : self::MELT_STATE_FRESH_TTL;
		set_transient( $cache_key, $state, $ttl );
		return $state;
	}

	/**
	 * Drop the cached melt-quote state for a quote, e.g. after the quote
	 * is rotated or the order is finalised against it.
	 */
	public static function flush_melt_quote_state( string $quote_id ): void {
		delete_transient( 'cashu_melt_state_' . md5( $quote_id ) );
	}

	/**
	 * Best-effort fetch of a NUT-04 mint-quote state. Returns the state
	 * string (e.g. UNPAID, PAID, ISSUED) or empty string if the lookup
	 * fails. Never throws — callers treat an empty return as "unknown" and
	 * preserve the existing quote rather than letting a single network
	 * hiccup orphan a paid one.
	 */
	public static function mint_quote_state( string $mint_url, string $quote_id ): string {
		if ( '' === $mint_url ) {
			return '';
		}
		$url = rtrim( $mint_url, '/' ) . '/v1/mint/quote/bolt11/' . rawurlencode( $quote_id );
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			Logger::debug( 'Mint quote state lookup failed: ' . $res->get_error_message() );
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			Logger::debug( 'Mint quote state lookup HTTP ' . $code );
			return '';
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) || empty( $json['state'] ) ) {
			return '';
		}
		return (string) $json['state'];
	}

	/**
	 * Request a NUT-04 mint quote: the BOLT11 the customer pays to mint
	 * proofs at the mint.
	 *
	 * @throws \RuntimeException on transport or mint error.
	 */
	public static function request_mint_quote( string $mint_url, int $amount_sats ): array {
		$endpoint = rtrim( $mint_url, '/' ) . '/v1/mint/quote/bolt11';
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
	 * Request a NUT-05 melt quote against a BOLT11 invoice: the quote the
	 * customer's proofs are later melted against to pay the merchant.
	 *
	 * @throws \RuntimeException on transport or mint error.
	 */
	public static function request_melt_quote( string $mint_url, string $bolt11 ): array {
		$endpoint = rtrim( $mint_url, '/' ) . '/v1/melt/quote/bolt11';
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
	 * Execute a NUT-05 melt: hand the mint a set of input proofs against an
	 * existing melt quote so it pays the underlying lightning invoice and
	 * (optionally) returns change proofs.
	 *
	 * @param string $mint_url Mint that issued the quote.
	 * @param string $quote_id The mint's melt quote id (stored on the order).
	 * @param array  $proofs   Array of proofs as decoded from the wallet's POST body.
	 *                         Each proof must contain id, amount, secret, C; witness optional.
	 *
	 * @throws \RuntimeException on transport or mint error.
	 */
	public static function melt( string $mint_url, string $quote_id, array $proofs ): array {
		$endpoint = rtrim( $mint_url, '/' ) . '/v1/melt/bolt11';

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

		// Real-world LN payments via the mint can take well over the default
		// 30s when routing is slow. 90s gives the mint room to respond
		// synchronously without our request timing out and orphaning the
		// melt as PENDING-without-our-knowledge.
		$args = array(
			'timeout' => 90,
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
}
