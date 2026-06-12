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

	/**
	 * Rendered output of every admin notice queued via Notice::addNotice
	 * (probe results use WP notices for their level styling — info/warning —
	 * which WC_Admin_Settings messages can't express).
	 */
	private array $notices = array();

	protected function setUp(): void {
		parent::setUp();
		$this->optionStore = array();
		$this->notices     = array();
		WC_Admin_Settings::reset();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback ) {
				if ( 'admin_notices' === $hook && is_callable( $callback ) ) {
					ob_start();
					$callback();
					$this->notices[] = (string) ob_get_clean();
				}
				return true;
			}
		);

		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return $this->optionStore[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) {
				$this->optionStore[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'number_format_i18n' )->alias(
			static fn ( $n ) => number_format( (float) $n )
		);
		ValidateGlobalSettings::reset_limits_notice();

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
	 * both NUT-04 and NUT-05, plus NUT-09 (restore) support. Pass overrides
	 * to mutate specific entries.
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
				'9' => array(
					'supported' => true,
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

	public function test_sanitize_trusted_mint_rejects_when_nut09_missing(): void {
		$body = $this->validNut06Body();
		unset( $body['nuts']['9'] );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->mintInfoResponse( $body ) );

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'NUT-09', WC_Admin_Settings::$errors[0] );
		$this->assertStringContainsString( 'recovery', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_trusted_mint_rejects_when_nut09_unsupported(): void {
		$body                          = $this->validNut06Body();
		$body['nuts']['9']['supported'] = false;
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->mintInfoResponse( $body ) );

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'NUT-09', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_trusted_mint_accepts_nut09_bare_true_shorthand(): void {
		// Some mints advertise settings nuts as a bare `true` rather than the
		// canonical {"supported": true}. Both must be accepted.
		$body              = $this->validNut06Body();
		$body['nuts']['9'] = true;
		Functions\expect( 'wp_remote_get' )->once()->andReturn( $this->mintInfoResponse( $body ) );

		$result = ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertSame( 'https://mint.example', $result );
		$this->assertCount( 0, WC_Admin_Settings::$errors );
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

	/**
	 * Build a valid LUD-06 LNURL-pay metadata body. Pass overrides to mutate
	 * specific fields.
	 */
	private function validLnurlpBody( array $overrides = array() ): array {
		$base = array(
			'tag'            => 'payRequest',
			'callback'       => 'https://lnurl.example/lnurlp/cb/abc',
			'minSendable'    => 1000,
			'maxSendable'    => 100000000,
			'metadata'       => '[["text/plain","Pay to me"]]',
			'commentAllowed' => 0,
		);
		return array_replace_recursive( $base, $overrides );
	}

	private function lnurlpResponse( array $body, int $code = 200 ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) json_encode( $body ),
		);
	}

	public function test_sanitize_lightning_address_accepts_valid_lnurlp_response(): void {
		Functions\when( 'is_email' )->alias( static fn ( $v ) => $v );
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://example.com/.well-known/lnurlp/me', \Mockery::any() )
			->andReturn( $this->lnurlpResponse( $this->validLnurlpBody() ) );

		$result = ValidateGlobalSettings::sanitize_lightning_address( 'me@example.com' );

		$this->assertSame( 'me@example.com', $result );
		$this->assertCount( 0, WC_Admin_Settings::$errors );
	}

	public function test_sanitize_lightning_address_skips_probe_when_unchanged(): void {
		// Admin re-saves settings without touching the LN address. We
		// MUST NOT hit the upstream provider — the value is already
		// known-good.
		$this->optionStore['cashu_lightning_address'] = 'me@example.com';
		Functions\when( 'is_email' )->alias( static fn ( $v ) => $v );
		Functions\expect( 'wp_remote_get' )->never();

		$result = ValidateGlobalSettings::sanitize_lightning_address( 'me@example.com' );

		$this->assertSame( 'me@example.com', $result );
		$this->assertCount( 0, WC_Admin_Settings::$errors );
	}

	public function test_sanitize_lightning_address_rejects_invalid_email_shape(): void {
		Functions\when( 'is_email' )->justReturn( false );
		Functions\expect( 'wp_remote_get' )->never();

		$result = ValidateGlobalSettings::sanitize_lightning_address( 'not-an-address' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'valid lightning address', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_lightning_address_rejects_when_provider_unreachable(): void {
		Functions\when( 'is_email' )->alias( static fn ( $v ) => $v );
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn( new \WP_Error( 'http_request_failed', 'connect timeout' ) );

		$result = ValidateGlobalSettings::sanitize_lightning_address( 'me@example.com' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'Could not reach the Lightning address provider', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_lightning_address_rejects_non_200_response(): void {
		Functions\when( 'is_email' )->alias( static fn ( $v ) => $v );
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn(
				array(
					'response' => array( 'code' => 404 ),
					'body'     => 'Not Found',
				)
			);

		$result = ValidateGlobalSettings::sanitize_lightning_address( 'me@example.com' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'returned HTTP 404', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_lightning_address_rejects_response_with_wrong_tag(): void {
		Functions\when( 'is_email' )->alias( static fn ( $v ) => $v );
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn(
				$this->lnurlpResponse(
					$this->validLnurlpBody( array( 'tag' => 'withdrawRequest' ) )
				)
			);

		$result = ValidateGlobalSettings::sanitize_lightning_address( 'me@example.com' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'payRequest', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_lightning_address_rejects_response_with_invalid_callback(): void {
		Functions\when( 'is_email' )->alias( static fn ( $v ) => $v );
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn(
				$this->lnurlpResponse(
					$this->validLnurlpBody( array( 'callback' => 'not-a-url' ) )
				)
			);

		$result = ValidateGlobalSettings::sanitize_lightning_address( 'me@example.com' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'invalid callback URL', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_lightning_address_rejects_response_with_inverted_send_bounds(): void {
		Functions\when( 'is_email' )->alias( static fn ( $v ) => $v );
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn(
				$this->lnurlpResponse(
					$this->validLnurlpBody(
						array(
							'minSendable' => 100000,
							'maxSendable' => 1000,
						)
					)
				)
			);

		$result = ValidateGlobalSettings::sanitize_lightning_address( 'me@example.com' );

		$this->assertNull( $result );
		$this->assertStringContainsString( 'invalid send-amount bounds', WC_Admin_Settings::$errors[0] );
	}

	public function test_sanitize_lightning_address_empty_value_passes_through(): void {
		Functions\expect( 'wp_remote_get' )->never();

		$result = ValidateGlobalSettings::sanitize_lightning_address( '   ' );

		$this->assertSame( '', $result );
		$this->assertCount( 0, WC_Admin_Settings::$errors );
	}

	// ── limits snapshot + merchant messaging at save time ────────────────

	public function test_successful_mint_probe_stores_limits_and_announces_them(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			$this->mintInfoResponse( $this->validNut06Body() )
		);

		ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$snapshot = $this->optionStore[ \Cashu\WC\Helpers\MintLimits::OPTION ] ?? array();
		$this->assertSame( 1, $snapshot['mint']['mint_min'] ?? null );
		$this->assertSame( 10000, $snapshot['mint']['mint_max'] ?? null );
		$this->assertSame( 100, $snapshot['mint']['melt_min'] ?? null );
		$this->assertSame( 10000, $snapshot['mint']['melt_max'] ?? null );

		$this->assertCount( 0, WC_Admin_Settings::$messages );
		$this->assertCount( 1, $this->notices );
		$this->assertStringContainsString( 'notice-info', $this->notices[0] );
		$this->assertStringContainsString( 'Mint Lightning limits', $this->notices[0] );
		$this->assertStringContainsString( '100–10,000 sat', $this->notices[0] );
	}

	public function test_successful_mint_probe_announces_the_mints_self_description(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			$this->mintInfoResponse(
				$this->validNut06Body(
					array(
						'description'      => 'DEVELOPMENT MINT.',
						'description_long' => 'All sats will be rugged monthly.',
					)
				)
			)
		);

		ValidateGlobalSettings::sanitize_trusted_mint( 'https://rugs.example/' );

		$snapshot = $this->optionStore[ \Cashu\WC\Helpers\MintLimits::OPTION ] ?? array();
		$this->assertSame(
			'DEVELOPMENT MINT. All sats will be rugged monthly.',
			$snapshot['mint']['description'] ?? null
		);

		$this->assertCount( 2, $this->notices );
		$this->assertStringContainsString( 'notice-warning', $this->notices[1] );
		$this->assertStringContainsString( 'Mint says:', $this->notices[1] );
		$this->assertStringContainsString( 'All sats will be rugged monthly.', $this->notices[1] );
	}

	public function test_successful_lnurl_probe_stores_limits_and_announces_them(): void {
		Functions\when( 'is_email' )->alias( static fn ( $v ) => $v );
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			$this->lnurlpResponse( $this->validLnurlpBody() ) // 1,000–100,000,000 msat = 1–100,000 sat
		);

		ValidateGlobalSettings::sanitize_lightning_address( 'me@example.com' );

		$snapshot = $this->optionStore[ \Cashu\WC\Helpers\MintLimits::OPTION ] ?? array();
		$this->assertSame( 1, $snapshot['lnurl']['min'] ?? null );
		$this->assertSame( 100000, $snapshot['lnurl']['max'] ?? null );

		$this->assertCount( 1, $this->notices );
		$this->assertStringContainsString( 'notice-info', $this->notices[0] );
		$this->assertStringContainsString( 'Lightning address accepts 1–100,000 sat', $this->notices[0] );
	}

	public function test_lnurl_probe_flags_address_narrower_than_melt_range(): void {
		// Mint can melt up to 10,000,000 sat, but the address caps at
		// 100,000 — the address is the real checkout limit; say so.
		$this->optionStore[ \Cashu\WC\Helpers\MintLimits::OPTION ] = array(
			'mint' => array(
				'url'        => 'https://mint.example',
				'mint_min'   => null,
				'mint_max'   => null,
				'melt_min'   => 1,
				'melt_max'   => 10000000,
				'fetched_at' => time(),
			),
		);
		Functions\when( 'is_email' )->alias( static fn ( $v ) => $v );
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			$this->lnurlpResponse( $this->validLnurlpBody() )
		);

		ValidateGlobalSettings::sanitize_lightning_address( 'me@example.com' );

		$this->assertCount( 2, $this->notices );
		$this->assertStringContainsString( 'notice-warning', $this->notices[1] );
		$this->assertStringContainsString( 'narrower than the mint', $this->notices[1] );
	}

	public function test_failed_mint_probe_does_not_touch_limits_snapshot(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			new \WP_Error( 'http_request_failed', 'connect timeout' )
		);

		ValidateGlobalSettings::sanitize_trusted_mint( 'https://mint.example/' );

		$this->assertArrayNotHasKey( \Cashu\WC\Helpers\MintLimits::OPTION, $this->optionStore );
		$this->assertCount( 0, WC_Admin_Settings::$messages );
	}
}
