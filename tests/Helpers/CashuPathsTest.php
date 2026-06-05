<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Helpers;

use Cashu\WC\Helpers\CashuPaths;
use PHPUnit\Framework\TestCase;

final class CashuPathsTest extends TestCase {

	public function test_sanitize_non_array_returns_all_enabled_default(): void {
		$this->assertSame(
			array( 'unified' => true, 'cashu' => true, 'lightning' => true ),
			CashuPaths::sanitize( null )
		);
	}

	public function test_sanitize_coerces_wc_checkbox_yes_no_strings(): void {
		$raw = array( 'unified' => 'yes', 'cashu' => 'no', 'lightning' => 'yes' );
		$this->assertSame(
			array( 'unified' => true, 'cashu' => false, 'lightning' => true ),
			CashuPaths::sanitize( $raw )
		);
	}

	public function test_sanitize_treats_missing_keys_as_false(): void {
		$this->assertSame(
			array( 'unified' => false, 'cashu' => true, 'lightning' => false ),
			CashuPaths::sanitize( array( 'cashu' => 'yes' ) )
		);
	}

	public function test_sanitize_ignores_unknown_keys(): void {
		$this->assertSame(
			array( 'unified' => true, 'cashu' => false, 'lightning' => false ),
			CashuPaths::sanitize( array( 'unified' => 'yes', 'sneaky' => 'yes' ) )
		);
	}

	public function test_any_enabled_true_when_one_path_on(): void {
		$this->assertTrue(
			CashuPaths::any_enabled( array( 'unified' => false, 'cashu' => true, 'lightning' => false ) )
		);
	}

	public function test_any_enabled_false_when_all_off(): void {
		$this->assertFalse(
			CashuPaths::any_enabled( array( 'unified' => false, 'cashu' => false, 'lightning' => false ) )
		);
	}

	public function test_enabled_keys_preserves_fixed_order(): void {
		$paths = array( 'lightning' => true, 'cashu' => true, 'unified' => true );
		$this->assertSame(
			array( 'unified', 'cashu', 'lightning' ),
			CashuPaths::enabled_keys( $paths )
		);
	}

	public function test_default_path_returns_stored_when_enabled(): void {
		$paths = array( 'unified' => false, 'cashu' => true, 'lightning' => true );
		$this->assertSame( 'cashu', CashuPaths::default_path( $paths, 'cashu' ) );
	}

	public function test_default_path_falls_back_to_first_enabled_when_stored_disabled(): void {
		$paths = array( 'unified' => false, 'cashu' => true, 'lightning' => true );
		$this->assertSame( 'cashu', CashuPaths::default_path( $paths, 'unified' ) );
	}

	public function test_default_path_falls_back_to_first_enabled_when_stored_unknown(): void {
		$paths = array( 'unified' => false, 'cashu' => false, 'lightning' => true );
		$this->assertSame( 'lightning', CashuPaths::default_path( $paths, 'gibberish' ) );
	}

	public function test_default_path_returns_unified_when_no_paths_enabled(): void {
		// Belt-and-braces: if a corrupted option somehow has all-false, the
		// resolver must still return *something* renderable rather than ''.
		$paths = array( 'unified' => false, 'cashu' => false, 'lightning' => false );
		$this->assertSame( 'unified', CashuPaths::default_path( $paths, 'cashu' ) );
	}

	public function test_any_enabled_false_when_paths_array_empty(): void {
		$this->assertFalse( CashuPaths::any_enabled( array() ) );
	}

	public function test_sanitize_is_idempotent_on_already_bool_input(): void {
		$input = array( 'unified' => true, 'cashu' => false, 'lightning' => true );
		$this->assertSame( $input, CashuPaths::sanitize( $input ) );
	}
}
