<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Helpers\CashuPaths;
use Cashu\WC\Tests\IntegrationTestCase;

final class IsAvailableTest extends IntegrationTestCase {

	/**
	 * Stubs the get_option() reads is_available() makes. Pass overrides to
	 * test missing/invalid values.
	 */
	private function stubOptions( array $overrides = array() ): void {
		$defaults = array(
			'cashu_lightning_address' => 'me@example.com',
			'cashu_trusted_mint'      => 'https://mint.example/Bitcoin',
			'cashu_paths'             => CashuPaths::DEFAULT_PATHS,
		);
		$values = array_merge( $defaults, $overrides );
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) use ( $values ) {
				return $values[ $key ] ?? $default;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_x' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		// The cart-total limits check is exercised by IsAvailableLimitsTest;
		// here there's no cart, so is_available() must skip it (fail open).
		// WC() needs an explicit stub: another test in the process may have
		// defined the function already, and Brain\Monkey then requires every
		// test that hits it to declare behaviour.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'WC' )->alias(
			static function (): \stdClass {
				$wc       = new \stdClass();
				$wc->cart = null;
				return $wc;
			}
		);
	}

	private function gateway( string $enabled = 'yes' ): CashuGateway {
		$gw = new CashuGateway();
		$gw->enabled = $enabled;
		return $gw;
	}

	public function test_returns_true_when_all_prerequisites_met(): void {
		$this->stubOptions();
		$this->assertTrue( $this->gateway()->is_available() );
	}

	public function test_returns_false_when_gateway_enabled_no(): void {
		$this->stubOptions();
		$this->assertFalse( $this->gateway( 'no' )->is_available() );
	}

	public function test_returns_false_when_lightning_address_empty(): void {
		$this->stubOptions( array( 'cashu_lightning_address' => '' ) );
		$this->assertFalse( $this->gateway()->is_available() );
	}

	public function test_returns_false_when_trusted_mint_empty(): void {
		$this->stubOptions( array( 'cashu_trusted_mint' => '' ) );
		$this->assertFalse( $this->gateway()->is_available() );
	}

	public function test_returns_false_when_no_paths_enabled(): void {
		$this->stubOptions(
			array( 'cashu_paths' => array( 'unified' => false, 'cashu' => false, 'lightning' => false ) )
		);
		$this->assertFalse( $this->gateway()->is_available() );
	}

	public function test_returns_true_when_only_one_path_enabled(): void {
		$this->stubOptions(
			array( 'cashu_paths' => array( 'unified' => false, 'cashu' => false, 'lightning' => true ) )
		);
		$this->assertTrue( $this->gateway()->is_available() );
	}

	public function test_does_not_read_legacy_cashu_enabled_option(): void {
		// Even with cashu_enabled = 'no', is_available() must succeed when
		// every other gate is open. Regression guard against the dual-toggle
		// surprise we're removing.
		$this->stubOptions( array( 'cashu_enabled' => 'no' ) );
		$this->assertTrue( $this->gateway()->is_available() );
	}
}
