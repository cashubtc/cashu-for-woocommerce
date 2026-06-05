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
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );

		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return $this->optionStore[ $key ] ?? $default;
			}
		);

		// Minimal URL plumbing stubs used by sanitize_trusted_mint. Real WP
		// versions are more sophisticated; we only need enough for these
		// tests to exercise the NUT-06 probe path.
		Functions\when( 'wp_http_validate_url' )->alias(
			function ( $url ) {
				return is_string( $url ) && preg_match( '#^https?://[^\s]+$#i', $url ) ? $url : false;
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'untrailingslashit' )->alias(
			function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $r ) {
				return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $r ) {
				return is_array( $r ) ? ( $r['body'] ?? '' ) : '';
			}
		);
	}

	/**
	 * Build a valid NUT-06 /v1/info response body advertising bolt11/sat on
	 * both NUT-04 and NUT-05. Pass overrides to mutate specific entries.
	 */
	private function validNut06Body( array $overrides = array() ): array {
		$base = array(
			'name' => 'Test Mint',
			'nuts' => array(
				'4' => array(
					'methods'  => array(
						array(
							'method'     => 'bolt11',
							'unit'       => 'sat',
							'min_amount' => 1,
							'max_amount' => 10000,
						),
					),
					'disabled' => false,
				),
				'5' => array(
					'methods'  => array(
						array(
							'method'     => 'bolt11',
							'unit'       => 'sat',
							'min_amount' => 100,
							'max_amount' => 10000,
						),
					),
					'disabled' => false,
				),
			),
		);
		return array_replace_recursive( $base, $overrides );
	}

	private function mintInfoResponse( array $body, int $code = 200 ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) json_encode( $body ),
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

	public function test_sanitize_trusted_mint_accepts_url_advertising_bolt11_sat_on_both_nuts(): void {
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://mint.example/v1/info', \Mockery::any() )
			->andReturn( $this->mintInfoResponse( $this->validNut06Body() ) );

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertSame( 'https://mint.example', $result );
		$this->assertCount( 0, WC_Admin_Settings::$errors );
	}

	public function test_sanitize_trusted_mint_skips_probe_when_url_unchanged(): void {
		// Admin re-saves settings without touching the mint URL. We should
		// NOT hit the network — the value is already known-good.
		$this->optionStore['cashu_trusted_mint'] = 'https://mint.example';
		Functions\expect( 'wp_remote_get' )->never();

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example' );

		$this->assertSame( 'https://mint.example', $result );
		$this->assertCount( 0, WC_Admin_Settings::$errors );
	}

	public function test_sanitize_trusted_mint_rejects_when_mint_unreachable(): void {
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn( new \WP_Error( 'http_request_failed', 'connect timeout' ) );

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertNull( $result );
		$this->assertCount( 1, WC_Admin_Settings::$errors );
		$this->assertStringContainsString( 'Could not reach the mint', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_trusted_mint_rejects_non_200_response(): void {
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn(
				array(
					'response' => array( 'code' => 404 ),
					'body'     => 'Not Found',
				)
			);

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'returned HTTP 404', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_trusted_mint_rejects_response_without_nuts(): void {
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn( $this->mintInfoResponse( array( 'name' => 'Not a mint' ) ) );

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'NUT capability list', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_trusted_mint_rejects_when_nut04_disabled(): void {
		$body                 = $this->validNut06Body();
		$body['nuts']['4']['disabled'] = true;
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->mintInfoResponse( $body ) );

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'NUT-04', WC_Admin_Settings::$errors[0] );
		$this->assertStringContainsString( 'does not advertise', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_trusted_mint_rejects_when_nut05_method_is_not_bolt11(): void {
		$body                       = $this->validNut06Body();
		$body['nuts']['5']['methods'] = array(
			array(
				'method'     => 'bolt12',
				'unit'       => 'sat',
				'min_amount' => 0,
				'max_amount' => 10000,
			),
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->mintInfoResponse( $body ) );

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'NUT-05', WC_Admin_Settings::$errors[0] );
		$this->assertStringContainsString( 'BOLT11/sat', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_trusted_mint_rejects_when_nut04_unit_is_not_sat(): void {
		$body                       = $this->validNut06Body();
		$body['nuts']['4']['methods'] = array(
			array(
				'method'     => 'bolt11',
				'unit'       => 'msat',
				'min_amount' => 0,
				'max_amount' => 10000,
			),
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->mintInfoResponse( $body ) );

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'NUT-04', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_trusted_mint_rejects_http_url_before_probing(): void {
		Functions\expect( 'wp_remote_get' )->never();

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'http://mint.example/' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'https', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_trusted_mint_empty_value_passes_through(): void {
		Functions\expect( 'wp_remote_get' )->never();

		$result = ValidateGlobalSettings::sanitize_trusted_mint( '   ' );

		$this->assertSame( '', $result );
		$this->assertCount( 0, WC_Admin_Settings::$errors );
	}
}
