<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Helpers;

use Cashu\WC\Helpers\Bolt11;
use PHPUnit\Framework\TestCase;

/**
 * Pure helper test (no Brain\Monkey / Mockery). Covers Bolt11::preimageMatches,
 * the primitive PayController + claim_melt_quote use to verify a mint-supplied
 * preimage without trusting the mint's PAID state.
 */
final class Bolt11PreimageMatchesTest extends TestCase {

	/**
	 * sha256(0x00) == 6e340b9cffb37a989ca544e6bb780a2c78901d3fb33738768511a30617afa01d
	 */
	private const ZERO_PREIMAGE = '00';
	private const ZERO_HASH     = '6e340b9cffb37a989ca544e6bb780a2c78901d3fb33738768511a30617afa01d';

	/**
	 * sha256( hex2bin('deadbeef') )
	 *   = 5f78c33274e43fa9de5659265c1d917e25c03722dcb0b8d27db8d5feaa813953
	 */
	private const DEADBEEF_PREIMAGE = 'deadbeef';
	private const DEADBEEF_HASH     = '5f78c33274e43fa9de5659265c1d917e25c03722dcb0b8d27db8d5feaa813953';

	public function test_matches_known_preimage_and_hash(): void {
		$this->assertTrue( Bolt11::preimageMatches( self::DEADBEEF_PREIMAGE, self::DEADBEEF_HASH ) );
		$this->assertTrue( Bolt11::preimageMatches( self::ZERO_PREIMAGE, self::ZERO_HASH ) );
	}

	public function test_mismatched_preimage_and_hash_returns_false(): void {
		$this->assertFalse( Bolt11::preimageMatches( self::DEADBEEF_PREIMAGE, self::ZERO_HASH ) );
	}

	public function test_uppercase_input_is_normalised(): void {
		$this->assertTrue(
			Bolt11::preimageMatches(
				strtoupper( self::DEADBEEF_PREIMAGE ),
				strtoupper( self::DEADBEEF_HASH )
			)
		);
	}

	public function test_whitespace_is_trimmed(): void {
		$this->assertTrue(
			Bolt11::preimageMatches(
				"  \n" . self::DEADBEEF_PREIMAGE . " \t",
				' ' . self::DEADBEEF_HASH . ' '
			)
		);
	}

	public function test_empty_inputs_return_false(): void {
		$this->assertFalse( Bolt11::preimageMatches( '', self::ZERO_HASH ) );
		$this->assertFalse( Bolt11::preimageMatches( self::ZERO_PREIMAGE, '' ) );
		$this->assertFalse( Bolt11::preimageMatches( '', '' ) );
	}

	public function test_non_hex_inputs_return_false(): void {
		// 'g' is not a hex digit.
		$this->assertFalse( Bolt11::preimageMatches( 'gg', self::ZERO_HASH ) );
		$this->assertFalse( Bolt11::preimageMatches( self::ZERO_PREIMAGE, 'gg' ) );
		// 'not-hex' contains '-'.
		$this->assertFalse( Bolt11::preimageMatches( 'not-hex', self::ZERO_HASH ) );
	}

	public function test_odd_length_hex_returns_false(): void {
		// Odd-length hex can't be decoded to bytes; ctype_xdigit passes but
		// hex2bin returns false. Documenting that we trap the failure.
		// hex2bin emits a PHP warning on odd-length input — suppressed here
		// because it's expected, not a sign of a test bug.
		$prev = set_error_handler(
			static fn ( int $errno, string $errstr ): bool =>
				false !== strpos( $errstr, 'hex2bin' )
		);
		try {
			$this->assertFalse( Bolt11::preimageMatches( '0', self::ZERO_HASH ) );
			$this->assertFalse( Bolt11::preimageMatches( '000', self::ZERO_HASH ) );
		} finally {
			restore_error_handler();
		}
	}

	public function test_uses_hash_equals_constant_time_compare(): void {
		// Sanity: only checking that a one-bit difference in the hash fails
		// — proves we're actually doing a byte compare, not a partial match.
		$almost = substr_replace( self::DEADBEEF_HASH, '0', -1, 1 );
		$this->assertFalse( Bolt11::preimageMatches( self::DEADBEEF_PREIMAGE, $almost ) );
	}
}
