<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Admin\ValidateGlobalSettings;
use Cashu\WC\Helpers\CashuPaths;
use Cashu\WC\Tests\IntegrationTestCase;
use WC_Admin_Settings;

final class ValidateGlobalSettingsTest extends IntegrationTestCase {

	private array $optionStore = array();

	protected function setUp(): void {
		parent::setUp();
		$this->optionStore = array();
		WC_Admin_Settings::reset();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );

		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return $this->optionStore[ $key ] ?? $default;
			}
		);
	}

	public function test_pre_update_paths_normalises_yes_no_to_bool_bitmap(): void {
		$old = CashuPaths::DEFAULT_PATHS;
		$new = array( 'unified' => 'yes', 'cashu' => 'yes', 'lightning' => 'yes' );

		$result = ValidateGlobalSettings::pre_update_paths( $new, $old, 'cashu_paths' );

		$this->assertSame(
			array( 'unified' => 'yes', 'cashu' => 'yes', 'lightning' => 'yes' ),
			$result
		);
		$this->assertCount( 0, WC_Admin_Settings::$errors );
		$this->assertCount( 0, WC_Admin_Settings::$messages );
	}

	public function test_pre_update_paths_returns_old_value_when_all_off(): void {
		$old = array( 'unified' => 'yes', 'cashu' => 'yes', 'lightning' => 'yes' );
		$new = array( 'unified' => 'no', 'cashu' => 'no', 'lightning' => 'no' );

		$result = ValidateGlobalSettings::pre_update_paths( $new, $old, 'cashu_paths' );

		$this->assertSame( $old, $result );
		$this->assertCount( 1, WC_Admin_Settings::$errors );
		$this->assertStringContainsString( 'at least one payment path', WC_Admin_Settings::$errors[0] );
	}

	public function test_pre_update_paths_falls_back_to_defaults_when_old_value_not_array(): void {
		// Fresh-install edge case: option doesn't exist yet, $old_value is false.
		$new = array( 'unified' => 'no', 'cashu' => 'no', 'lightning' => 'no' );

		$result = ValidateGlobalSettings::pre_update_paths( $new, false, 'cashu_paths' );

		$this->assertSame(
			array( 'unified' => 'yes', 'cashu' => 'yes', 'lightning' => 'yes' ),
			$result
		);
		$this->assertCount( 1, WC_Admin_Settings::$errors );
	}

	public function test_pre_update_paths_disables_unified_when_cashu_off(): void {
		$old = array( 'unified' => 'yes', 'cashu' => 'yes', 'lightning' => 'yes' );
		$new = array( 'unified' => 'yes', 'cashu' => 'no', 'lightning' => 'yes' );

		$result = ValidateGlobalSettings::pre_update_paths( $new, $old, 'cashu_paths' );

		$this->assertSame(
			array( 'unified' => 'no', 'cashu' => 'no', 'lightning' => 'yes' ),
			$result
		);
		$this->assertCount( 1, WC_Admin_Settings::$messages );
		$this->assertStringContainsString( 'Unified payments need both', WC_Admin_Settings::$messages[0] );
	}

	public function test_pre_update_paths_disables_unified_when_lightning_off(): void {
		$old = array( 'unified' => 'yes', 'cashu' => 'yes', 'lightning' => 'yes' );
		$new = array( 'unified' => 'yes', 'cashu' => 'yes', 'lightning' => 'no' );

		$result = ValidateGlobalSettings::pre_update_paths( $new, $old, 'cashu_paths' );

		$this->assertSame(
			array( 'unified' => 'no', 'cashu' => 'yes', 'lightning' => 'no' ),
			$result
		);
		$this->assertCount( 1, WC_Admin_Settings::$messages );
	}

	protected function tearDown(): void {
		unset( $_POST['cashu_paths'] );
		parent::tearDown();
	}

	public function test_sanitize_default_path_passes_through_valid_enabled_choice(): void {
		$_POST['cashu_paths'] = array( 'unified' => 'no', 'cashu' => 'yes', 'lightning' => 'no' );

		$result = ValidateGlobalSettings::sanitize_default_path( 'cashu', array( 'id' => 'cashu_default_path' ), 'cashu' );

		$this->assertSame( 'cashu', $result );
		$this->assertCount( 0, WC_Admin_Settings::$errors );
		$this->assertCount( 0, WC_Admin_Settings::$messages );
	}

	public function test_sanitize_default_path_coerces_to_first_enabled_when_choice_disabled(): void {
		$_POST['cashu_paths'] = array( 'unified' => 'no', 'cashu' => 'yes', 'lightning' => 'yes' );

		$result = ValidateGlobalSettings::sanitize_default_path( 'unified', array( 'id' => 'cashu_default_path' ), 'unified' );

		$this->assertSame( 'cashu', $result );
		$this->assertCount( 1, WC_Admin_Settings::$messages );
		$this->assertStringContainsString( 'Default tab', WC_Admin_Settings::$messages[0] );
	}

	public function test_sanitize_default_path_rejects_unknown_string(): void {
		$_POST['cashu_paths'] = array( 'unified' => 'yes', 'cashu' => 'yes', 'lightning' => 'yes' );

		$result = ValidateGlobalSettings::sanitize_default_path( 'gibberish', array( 'id' => 'cashu_default_path' ), 'gibberish' );

		$this->assertSame( 'unified', $result );
		$this->assertCount( 1, WC_Admin_Settings::$messages );
	}

	public function test_default_path_coerces_to_first_enabled_after_unified_legs_coercion(): void {
		// Submission: user keeps Unified ticked, unticks Cashu, leaves
		// default_path='unified'. pre_update_paths will coerce Unified off;
		// sanitize_default_path must see that future state and snap the
		// default to the first remaining enabled path (lightning).
		$_POST['cashu_paths'] = array( 'unified' => 'yes', 'cashu' => 'no', 'lightning' => 'yes' );

		$result = ValidateGlobalSettings::sanitize_default_path( 'unified', array( 'id' => 'cashu_default_path' ), 'unified' );

		$this->assertSame( 'lightning', $result );
		$this->assertCount( 1, WC_Admin_Settings::$messages );
		$this->assertStringContainsString( 'Default tab', WC_Admin_Settings::$messages[0] );
	}
}
