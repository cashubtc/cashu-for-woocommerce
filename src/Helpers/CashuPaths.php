<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * Path-bitmap helpers for the Cashu checkout's three tabs (Unified / Cashu /
 * Lightning). Pure functions — no WordPress dependencies — so the logic can
 * be exercised under tests/Helpers/ without Brain\Monkey.
 */
final class CashuPaths {

	public const KEYS = array( 'unified', 'cashu', 'lightning' );

	public const DEFAULT_PATHS = array(
		'unified'   => true,
		'cashu'     => true,
		'lightning' => true,
	);

	public const DEFAULT_PATH = 'unified';

	/**
	 * Coerce arbitrary input (POSTed checkbox array, persisted option,
	 * filter return) into the canonical {unified,cashu,lightning} => bool
	 * shape. Unknown input returns the "all enabled" default — a corrupted
	 * option shouldn't take checkout offline.
	 */
	public static function sanitize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return self::DEFAULT_PATHS;
		}
		$out = array();
		foreach ( self::KEYS as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) && self::truthy( $raw[ $key ] );
		}
		return $out;
	}

	/**
	 * True iff at least one path key in $paths is strictly true. Expects the
	 * output of self::sanitize().
	 */
	public static function any_enabled( array $paths ): bool {
		foreach ( self::KEYS as $key ) {
			if ( true === ( $paths[ $key ] ?? false ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the subset of KEYS whose value is strictly true. Expects the
	 * output of self::sanitize().
	 */
	public static function enabled_keys( array $paths ): array {
		$out = array();
		foreach ( self::KEYS as $key ) {
			if ( true === ( $paths[ $key ] ?? false ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Resolve which tab should open first. Prefers $stored when enabled;
	 * otherwise the first enabled path in fixed order. Returns 'unified' as
	 * a last-resort sentinel so the JS always has a valid mode string even
	 * if every path is somehow false.
	 */
	public static function default_path( array $paths, string $stored ): string {
		if ( in_array( $stored, self::KEYS, true ) && true === ( $paths[ $stored ] ?? false ) ) {
			return $stored;
		}
		$enabled = self::enabled_keys( $paths );
		return $enabled[0] ?? self::DEFAULT_PATH;
	}

	private static function truthy( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_string( $v ) ) {
			return in_array( strtolower( $v ), array( 'yes', '1', 'true', 'on' ), true );
		}
		if ( is_int( $v ) ) {
			return 1 === $v;
		}
		return (bool) $v;
	}
}
