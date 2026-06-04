<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * Minimal BOLT11 invoice parser, scoped to extracting the payment_hash.
 *
 * A bolt11 invoice is bech32-encoded; the data section starts with a 35-bit
 * timestamp and is followed by a series of tagged fields (5-bit tag, 10-bit
 * length, length × 5-bit data). The 'p' tag (binary 00001) carries the
 * 256-bit SHA-256 payment_hash. The last 520 bits before the 30-bit checksum
 * are the signature. See BOLT-11 spec.
 *
 * We only need payment_hash to verify a preimage claim, so this implementation
 * stops at the first 'p' tag and ignores signature/checksum entirely.
 */
final class Bolt11 {

	private const BECH32_ALPHABET     = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
	private const TAG_PAYMENT_HASH    = 1;
	private const PAYMENT_HASH_GROUPS = 52;  // 256-bit hash packed into 52 × 5-bit groups
	private const SIGNATURE_GROUPS    = 104; // 520 bits / 5
	private const CHECKSUM_GROUPS     = 6;   // 30 bits / 5
	private const TIMESTAMP_GROUPS    = 7;   // 35 bits / 5

	/**
	 * Return the lowercase hex payment_hash for the invoice, or null if it
	 * can't be parsed.
	 */
	public static function paymentHash( string $invoice ): ?string {
		$invoice = strtolower( trim( $invoice ) );
		if ( '' === $invoice ) {
			return null;
		}

		$sep = strrpos( $invoice, '1' );
		if ( false === $sep || $sep < 2 ) {
			return null;
		}

		$data_chars = substr( $invoice, $sep + 1 );
		$values     = array();
		for ( $i = 0, $n = strlen( $data_chars ); $i < $n; $i++ ) {
			$pos = strpos( self::BECH32_ALPHABET, $data_chars[ $i ] );
			if ( false === $pos ) {
				return null;
			}
			$values[] = $pos;
		}

		$tagged_end = count( $values ) - self::SIGNATURE_GROUPS - self::CHECKSUM_GROUPS;
		if ( $tagged_end < self::TIMESTAMP_GROUPS + 3 ) {
			return null;
		}

		$i = self::TIMESTAMP_GROUPS;
		while ( $i + 3 <= $tagged_end ) {
			$tag = $values[ $i ];
			$len = ( $values[ $i + 1 ] << 5 ) | $values[ $i + 2 ];
			$i  += 3;

			if ( $i + $len > $tagged_end ) {
				return null;
			}

			// Per BOLT-11: payment_hash p-tag MUST have data_length=52.
			// Readers must skip any tag whose data is non-conforming, so a
			// p-tag of a different length is treated as not-payment-hash
			// rather than silently truncated/padded.
			if ( self::TAG_PAYMENT_HASH === $tag && self::PAYMENT_HASH_GROUPS === $len ) {
				return self::groupsToHex( array_slice( $values, $i, $len ), 256 );
			}

			$i += $len;
		}

		return null;
	}

	/**
	 * sha256(preimage) == payment_hash, both compared in lowercase hex.
	 * Used to cryptographically verify a settlement claim without
	 * contacting the mint.
	 */
	public static function preimageMatches( string $preimage_hex, string $payment_hash_hex ): bool {
		$preimage_hex     = strtolower( trim( $preimage_hex ) );
		$payment_hash_hex = strtolower( trim( $payment_hash_hex ) );
		if ( '' === $preimage_hex || '' === $payment_hash_hex ) {
			return false;
		}
		if ( ! ctype_xdigit( $preimage_hex ) || ! ctype_xdigit( $payment_hash_hex ) ) {
			return false;
		}
		$bytes = hex2bin( $preimage_hex );
		if ( false === $bytes ) {
			return false;
		}
		return hash_equals( $payment_hash_hex, hash( 'sha256', $bytes ) );
	}

	/**
	 * Pack an array of 5-bit values into the leading $bits bits, returned as
	 * lowercase hex. Used to convert the 52 5-bit groups of the 'p' tag into
	 * the 256-bit payment_hash.
	 */
	private static function groupsToHex( array $groups, int $bits ): string {
		$buf = '';
		foreach ( $groups as $g ) {
			$buf .= str_pad( decbin( $g ), 5, '0', STR_PAD_LEFT );
		}
		$buf = substr( $buf, 0, $bits );
		$hex = '';
		for ( $j = 0; $j < $bits; $j += 8 ) {
			$hex .= str_pad( dechex( (int) bindec( substr( $buf, $j, 8 ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return $hex;
	}
}
