<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Admin\GlobalSettings;
use Cashu\WC\Helpers\MintLimits;
use Cashu\WC\Tests\IntegrationTestCase;

final class GlobalSettingsTest extends IntegrationTestCase {

	private array $optionStore = array();

	protected function setUp(): void {
		parent::setUp();
		$this->optionStore = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn ( $n ) => abs( (int) $n ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => number_format( (float) $n ) );
		Functions\when( 'human_time_diff' )->justReturn( '5 mins' );
		// Logger::getLogFileUrl() plumbing for the debug field's desc.
		Functions\when( 'sanitize_file_name' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ) => 'https://shop/wp-admin/' . $p );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return $this->optionStore[ $key ] ?? $default;
			}
		);
	}

	private function fields(): array {
		return ( new GlobalSettings() )->get_settings_for_default_section();
	}

	private function seedSnapshot( string $mint_url, string $address ): void {
		$this->optionStore[ MintLimits::OPTION ] = array(
			'mint'  => array(
				'url'        => $mint_url,
				'mint_min'   => 1,
				'mint_max'   => 1000000,
				'melt_min'   => 100,
				'melt_max'   => 1000000,
				'fetched_at' => time() - 300,
			),
			'lnurl' => array(
				'address'    => $address,
				'min'        => 1,
				'max'        => 500000,
				'fetched_at' => time() - 300,
			),
		);
	}

	public function test_settings_include_expected_field_ids(): void {
		$ids = array_column( $this->fields(), 'id' );

		foreach ( array( 'cashu_lightning_address', 'cashu_trusted_mint', 'cashu_default_path', 'cashu_debug' ) as $expected ) {
			$this->assertContains( $expected, $ids );
		}
	}

	public function test_limit_descs_empty_without_snapshot(): void {
		$this->optionStore['cashu_trusted_mint']      = 'https://mint.example/Bitcoin';
		$this->optionStore['cashu_lightning_address'] = 'me@example.com';

		$fields = $this->fields();

		$this->assertSame( '', $fields['trusted_mint']['desc'] );
		$this->assertSame( '', $fields['lightning_address']['desc'] );
	}

	public function test_limit_descs_render_ranges_for_matching_snapshot(): void {
		$this->optionStore['cashu_trusted_mint']      = 'https://mint.example/Bitcoin';
		$this->optionStore['cashu_lightning_address'] = 'me@example.com';
		$this->seedSnapshot( 'https://mint.example/Bitcoin', 'me@example.com' );

		$fields = $this->fields();

		$this->assertStringContainsString( '100–1,000,000 sat', $fields['trusted_mint']['desc'] );
		$this->assertStringContainsString( '5 mins', $fields['trusted_mint']['desc'] );
		$this->assertStringContainsString( '1–500,000 sat', $fields['lightning_address']['desc'] );
	}

	public function test_mint_desc_leads_with_the_mints_self_description(): void {
		Functions\when( 'esc_html' )->returnArg( 1 );
		$this->optionStore['cashu_trusted_mint'] = 'https://mint.example/Bitcoin';
		$this->seedSnapshot( 'https://mint.example/Bitcoin', 'me@example.com' );
		$this->optionStore[ MintLimits::OPTION ]['mint']['description'] = 'It will eventually rug pull you.';

		$desc = $this->fields()['trusted_mint']['desc'];

		$this->assertStringContainsString( 'Mint says:', $desc );
		$this->assertStringContainsString( 'It will eventually rug pull you.', $desc );
		// Description leads, limits follow on their own line.
		$this->assertStringContainsString( '<br>', $desc );
		$this->assertStringContainsString( '100–1,000,000 sat', $desc );
	}

	public function test_limit_descs_empty_when_snapshot_is_for_other_source(): void {
		// Admin changed mint/address since the snapshot was taken — showing
		// the old source's limits under the new value would mislead.
		$this->optionStore['cashu_trusted_mint']      = 'https://new-mint.example';
		$this->optionStore['cashu_lightning_address'] = 'new@example.com';
		$this->seedSnapshot( 'https://old-mint.example', 'old@example.com' );

		$fields = $this->fields();

		$this->assertSame( '', $fields['trusted_mint']['desc'] );
		$this->assertSame( '', $fields['lightning_address']['desc'] );
	}
}
