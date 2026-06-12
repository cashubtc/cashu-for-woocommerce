<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Admin\GlobalSettings;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * Pins the PHP→JS boundary for the admin settings screen, in the same
 * spirit as EnqueueScriptsContractTest: cashu-settings.js and
 * cashu-mint-picker.js receive their config/strings only via
 * wp_localize_script, so a renamed key fails silently in the browser.
 */
final class AdminEnqueueContractTest extends IntegrationTestCase {

	private array $enqueued  = array();
	private array $localized = array();

	protected function setUp(): void {
		parent::setUp();
		$this->enqueued  = array();
		$this->localized = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle ) {
				$this->enqueued[] = $handle;
				return true;
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			function ( string $handle, string $object_name, array $data ) {
				$this->localized[ $object_name ] = $data;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		unset( $_GET['tab'] );
		parent::tearDown();
	}

	private function runEnqueue( string $hook = 'woocommerce_page_wc-settings', string $tab = 'cashu_settings' ): void {
		$_GET['tab'] = $tab;
		( new GlobalSettings() )->enqueue_admin_assets( $hook );
	}

	public function test_enqueues_both_admin_scripts_on_our_tab(): void {
		$this->runEnqueue();

		$this->assertSame( array( 'cashu-settings-admin', 'cashu-mint-picker' ), $this->enqueued );
	}

	public function test_other_hook_or_tab_enqueues_nothing(): void {
		$this->runEnqueue( 'edit.php' );
		$this->runEnqueue( 'woocommerce_page_wc-settings', 'shipping' );

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_settings_payload_keys_are_pinned(): void {
		$this->runEnqueue();

		$settings = $this->localized['cashuSettingsL10n'];
		$this->assertSame( array( 'labels', 'requiresBoth' ), array_keys( $settings ) );
		$this->assertSame( array( 'unified', 'cashu', 'lightning' ), array_keys( $settings['labels'] ) );
	}

	public function test_picker_payload_carries_starters_and_strings(): void {
		$this->runEnqueue();

		$picker = $this->localized['cashuMintPickerL10n'];
		$this->assertSame( array( 'auditorApi', 'starterMints', 'i18n' ), array_keys( $picker ) );

		$this->assertSame( 'https://api.audit.8333.space', $picker['auditorApi'] );

		// Starter set mirrors numo's defaults; first entry must match the
		// trusted-mint field default in get_settings_for_default_section().
		$urls = array_column( $picker['starterMints'], 'url' );
		$this->assertSame(
			array(
				'https://mint.minibits.cash/Bitcoin',
				'https://mint.chorus.community',
				'https://mint.cubabitcoin.org',
				'https://mint.coinos.io',
			),
			$urls
		);
		foreach ( $picker['starterMints'] as $mint ) {
			$this->assertSame( array( 'name', 'url' ), array_keys( $mint ) );
			$this->assertNotSame( '', $mint['name'] );
		}

		// Keys consumed by cashu-mint-picker.js — keep in sync, that's the point.
		$this->assertSame(
			array( 'placeholder', 'discover', 'discovering', 'failed' ),
			array_keys( $picker['i18n'] )
		);
	}
}
