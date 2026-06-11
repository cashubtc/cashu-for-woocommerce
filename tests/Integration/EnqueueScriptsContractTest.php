<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * Pins the PHP→JS boundary: the wp_localize_script payloads are the only
 * channel through which checkout.ts / thanks.js receive their config and
 * strings. A key renamed on either side fails silently in the browser
 * (undefined route → dead checkout; missing i18n → raw key shown to the
 * customer), and the live e2e suite is the only other thing that would
 * notice. These assertions fail at unit speed instead.
 */
final class EnqueueScriptsContractTest extends IntegrationTestCase {

	/**
	 * i18n keys consumed by the TS/JS bundles (t('...') call sites in
	 * src/ts/*.ts and assets/js/frontend/thanks.js). Keep in sync when a
	 * t() call is added — that's the point of the test.
	 */
	private const CHECKOUT_I18N_USED = array(
		'data_incomplete',
		'invoice_failed',
		'copied',
		'waiting_for_payment',
		'change_from_network',
		'paying_invoice',
		'payment_confirmed',
		'payment_received',
		'recovering_proofs',
		'recovery_failed_contact',
	);

	private const THANKS_I18N_USED = array(
		'title',
		'dismiss',
		'lead',
		'no_wallet',
		'important',
		'tip',
		'dust_badge',
		'dust_note',
		'copy',
		'copied',
		'copy_failed',
		'show',
		'hide',
		'change',
		'meta_amount',
	);

	private array $localized = array();
	private array $registered = array();

	private function runEnqueue(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'rest_url' )->alias( static fn ( string $p ): string => 'https://shop/wp-json/' . $p );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'wp_register_script' )->alias(
			function ( string $handle ) {
				$this->registered[] = $handle;
				return true;
			}
		);
		Functions\when( 'wp_register_style' )->alias(
			function ( string $handle ) {
				$this->registered[] = $handle;
				return true;
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			function ( string $handle, string $object_name, array $data ) {
				$this->localized[ $object_name ] = $data;
				return true;
			}
		);

		( new CashuGateway() )->enqueue_scripts();
	}

	public function test_registers_the_three_frontend_assets(): void {
		$this->runEnqueue();

		$this->assertSame( array( 'cashu-checkout', 'cashu-thanks', 'cashu-public' ), $this->registered );
	}

	public function test_checkout_config_carries_rest_routes_matching_the_controllers(): void {
		$this->runEnqueue();

		$config = $this->localized['cashu_wc'];
		$this->assertSame(
			array( 'rest_root', 'confirm_route', 'claim_route', 'symbol', 'qr_icons', 'i18n' ),
			array_keys( $config )
		);

		// These three values compose the URLs checkout.ts polls; they must
		// agree with the routes ConfirmMeltQuoteController registers (see
		// RestRoutesAndPermissionsTest, namespace cashu-wc/v1).
		$this->assertSame( 'https://shop/wp-json/cashu-wc/v1/', $config['rest_root'] );
		$this->assertSame( 'confirm-melt-quote', $config['confirm_route'] );
		$this->assertSame( 'claim-melt-quote', $config['claim_route'] );

		$this->assertSame( array( 'cashu', 'lightning' ), array_keys( $config['qr_icons'] ) );
	}

	public function test_checkout_i18n_covers_every_key_the_ts_consumes(): void {
		$this->runEnqueue();

		$provided = array_keys( $this->localized['cashu_wc']['i18n'] );
		foreach ( self::CHECKOUT_I18N_USED as $key ) {
			$this->assertContains( $key, $provided, "checkout.ts t('$key') has no localized string" );
		}
	}

	public function test_thanks_i18n_covers_every_key_the_js_consumes(): void {
		$this->runEnqueue();

		$thanks = $this->localized['cashu_wc_thanks'];
		$this->assertSame( array( 'symbol', 'i18n' ), array_keys( $thanks ) );

		$provided = array_keys( $thanks['i18n'] );
		foreach ( self::THANKS_I18N_USED as $key ) {
			$this->assertContains( $key, $provided, "thanks.js t('$key') has no localized string" );
		}
	}
}
