<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\CashuWCPlugin;
use Cashu\WC\Tests\IntegrationTestCase;

final class SettingsMigrationTest extends IntegrationTestCase {

	/**
	 * Wire up a stateful in-memory options store so update_option /
	 * delete_option / get_option round-trip correctly. Returns the array by
	 * reference so tests can assert on its final contents.
	 */
	private function stubOptionsStore( array &$store ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value, $autoload = null ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $key ) use ( &$store ) {
				$existed = array_key_exists( $key, $store );
				unset( $store[ $key ] );
				return $existed;
			}
		);
	}

	public function test_migration_flips_gateway_enable_when_cashu_enabled_was_yes(): void {
		$store = array(
			'cashu_enabled'                      => 'yes',
			'woocommerce_cashu_default_settings' => array( 'enabled' => 'no', 'title' => 'Cashu ecash' ),
		);
		$this->stubOptionsStore( $store );

		CashuWCPlugin::maybeMigrateSettings();

		$this->assertSame( 'yes', $store['woocommerce_cashu_default_settings']['enabled'] );
		$this->assertArrayNotHasKey( 'cashu_enabled', $store );
		$this->assertSame( 'yes', $store['cashu_settings_migrated'] );
	}

	public function test_migration_leaves_gateway_enable_alone_when_already_yes(): void {
		$store = array(
			'cashu_enabled'                      => 'yes',
			'woocommerce_cashu_default_settings' => array( 'enabled' => 'yes' ),
		);
		$this->stubOptionsStore( $store );

		CashuWCPlugin::maybeMigrateSettings();

		$this->assertSame( 'yes', $store['woocommerce_cashu_default_settings']['enabled'] );
		$this->assertArrayNotHasKey( 'cashu_enabled', $store );
		$this->assertSame( 'yes', $store['cashu_settings_migrated'] );
	}

	public function test_migration_does_not_flip_gateway_when_cashu_enabled_was_no(): void {
		$store = array(
			'cashu_enabled'                      => 'no',
			'woocommerce_cashu_default_settings' => array( 'enabled' => 'no' ),
		);
		$this->stubOptionsStore( $store );

		CashuWCPlugin::maybeMigrateSettings();

		$this->assertSame( 'no', $store['woocommerce_cashu_default_settings']['enabled'] );
		$this->assertArrayNotHasKey( 'cashu_enabled', $store );
		$this->assertSame( 'yes', $store['cashu_settings_migrated'] );
	}

	public function test_migration_is_idempotent(): void {
		$store = array(
			'cashu_settings_migrated'            => 'yes',
			'woocommerce_cashu_default_settings' => array( 'enabled' => 'no' ),
			// Hostile state: a stray cashu_enabled left over — migration must
			// NOT re-run and accidentally flip the gateway back on after the
			// admin deliberately turned it off post-migration.
			'cashu_enabled'                      => 'yes',
		);
		$this->stubOptionsStore( $store );

		CashuWCPlugin::maybeMigrateSettings();

		$this->assertSame( 'no', $store['woocommerce_cashu_default_settings']['enabled'] );
		$this->assertArrayHasKey( 'cashu_enabled', $store );
	}

	public function test_migration_handles_fresh_install_no_legacy_option(): void {
		$store = array();
		$this->stubOptionsStore( $store );

		CashuWCPlugin::maybeMigrateSettings();

		$this->assertSame( 'yes', $store['cashu_settings_migrated'] );
		$this->assertArrayNotHasKey( 'cashu_enabled', $store );
		$this->assertArrayNotHasKey( 'woocommerce_cashu_default_settings', $store );
	}
}
