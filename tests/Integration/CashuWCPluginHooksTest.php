<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Cashu\WC\CashuWCPlugin;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * The plugin bootstrap's hook handlers: gateway/settings registration,
 * WC compat declarations, admin notice scheduling, the review-notice
 * dismiss AJAX (a site-wide option write gated on nonce + capability),
 * and the order-screen render filters.
 */
final class CashuWCPluginHooksTest extends IntegrationTestCase {

	private array $optionStore = array();

	protected function setUp(): void {
		parent::setUp();
		$this->optionStore = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => number_format( (float) $n ) );
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
	}

	protected function tearDown(): void {
		unset( $_POST['dismiss_forever'] );
		parent::tearDown();
	}

	public function test_init_payment_gateways_appends_the_gateway(): void {
		$gateways = CashuWCPlugin::initPaymentGateways( array( 'WC_Gateway_BACS' ) );

		$this->assertSame( array( 'WC_Gateway_BACS', CashuGateway::class ), $gateways );
	}

	public function test_settings_page_registered_only_in_admin(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$plugin = CashuWCPlugin::instance();

		Functions\when( 'is_admin' )->justReturn( false );
		$this->assertSame( array(), $plugin->registerSettingsPage( array() ) );

		Functions\when( 'is_admin' )->justReturn( true );
		$pages = $plugin->registerSettingsPage( array() );
		$this->assertInstanceOf( \Cashu\WC\Admin\GlobalSettings::class, $pages[0] );
	}

	public function test_declares_hpos_and_blocks_compatibility(): void {
		\CashuTestFeaturesUtil::$declared = array();

		CashuWCPlugin::instance()->declareWooCompat();

		$features = array_column( \CashuTestFeaturesUtil::$declared, 0 );
		$this->assertSame( array( 'custom_order_tables', 'cart_checkout_blocks' ), $features );
		foreach ( \CashuTestFeaturesUtil::$declared as [ , $file, $enabled ] ) {
			$this->assertTrue( $enabled, 'compatibility must be declared as supported' );
		}
	}

	public function test_blocks_support_hooks_the_payment_method_registration(): void {
		Actions\expectAdded( 'woocommerce_blocks_payment_method_type_registration' )->once();

		CashuWCPlugin::blocksSupport();

		$this->assertTrue( true ); // Brain\Monkey verifies on tearDown.
	}

	// ── review-notice dismiss AJAX ───────────────────────────────────────

	/** wp_send_json_* exit in WP; emulate with an exception so flow halts. */
	private function stubJsonResponses(): void {
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ): void {
				throw new \RuntimeException( 'json_error:' . (string) $status );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function (): void {
				throw new \RuntimeException( 'json_success' );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
	}

	public function test_ajax_dismiss_rejects_bad_nonce_with_401(): void {
		$this->stubJsonResponses();
		Functions\when( 'check_ajax_referer' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		$this->expectExceptionMessage( 'json_error:401' );
		CashuWCPlugin::instance()->processAjaxNotification();
	}

	public function test_ajax_dismiss_rejects_non_managers_with_403(): void {
		// The nonce alone is not enough: any logged-in user can read a nonce
		// out of their own admin DOM, and this handler writes a site option.
		$this->stubJsonResponses();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		$this->expectExceptionMessage( 'json_error:403' );
		CashuWCPlugin::instance()->processAjaxNotification();
	}

	public function test_ajax_dismiss_forever_writes_the_option(): void {
		$this->stubJsonResponses();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST['dismiss_forever'] = '1';

		try {
			CashuWCPlugin::instance()->processAjaxNotification();
			$this->fail( 'expected json_success' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_success', $e->getMessage() );
		}
		$this->assertTrue( $this->optionStore['cashu_review_dismissed_forever'] );
	}

	public function test_ajax_dismiss_later_sets_transient_not_option(): void {
		$this->stubJsonResponses();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST['dismiss_forever'] = '0';
		Functions\expect( 'set_transient' )->once()
			->with( 'cashu_review_dismissed', true, \Mockery::type( 'int' ) )
			->andReturn( true );

		try {
			CashuWCPlugin::instance()->processAjaxNotification();
			$this->fail( 'expected json_success' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_success', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( 'cashu_review_dismissed_forever', $this->optionStore );
	}

	public function test_admin_scripts_and_nonce_withheld_from_non_managers(): void {
		// The localized nonce feeds the dismiss handler above; enqueueing it
		// for every wp-admin visitor would hand subscribers a valid nonce.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_localize_script' )->never();

		CashuWCPlugin::instance()->enqueueAdminScripts();

		$this->assertTrue( true ); // Brain\Monkey verifies on tearDown.
	}

	public function test_admin_scripts_localize_ajax_url_and_nonce_for_managers(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => 'https://shop/wp-admin/' . $p );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\expect( 'wp_enqueue_script' )->once()->andReturn( true );

		$captured = array();
		Functions\when( 'wp_localize_script' )->alias(
			static function ( string $handle, string $name, array $data ) use ( &$captured ): bool {
				$captured = $data;
				return true;
			}
		);

		CashuWCPlugin::instance()->enqueueAdminScripts();

		$this->assertSame( 'https://shop/wp-admin/admin-ajax.php', $captured['ajax_url'] );
		$this->assertSame( 'nonce123', $captured['nonce'] );
	}

	// ── admin notices ────────────────────────────────────────────────────

	/** Notices register via Notice::addNotice → add_action('admin_notices'). */
	private function runAdminNotices(): int {
		$count = 0;
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$count ): bool {
				if ( 'admin_notices' === $hook ) {
					++$count;
				}
				return true;
			}
		);
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		CashuWCPlugin::instance()->registerAdminNotices();
		return $count;
	}

	public function test_unconfigured_plugin_queues_setup_notice_and_review_nag_waits(): void {
		// Fresh install: no LN address / mint → "not configured" notice; the
		// review nag must NOT fire yet — first run only stamps the 30-day
		// earliest-show option.
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => 'https://shop/wp-admin/' . $p );

		$notices = $this->runAdminNotices();

		$this->assertSame( 1, $notices );
		$this->assertGreaterThan( time(), (int) $this->optionStore['cashu_review_earliest_show'] );
	}

	public function test_review_nag_fires_after_the_grace_period(): void {
		$this->optionStore['cashu_lightning_address']    = 'me@example.com';
		$this->optionStore['cashu_trusted_mint']         = 'https://mint.example';
		$this->optionStore['cashu_review_earliest_show'] = time() - 60;

		$this->assertSame( 1, $this->runAdminNotices() );
	}

	public function test_review_nag_respects_forever_dismissal(): void {
		$this->optionStore['cashu_lightning_address']         = 'me@example.com';
		$this->optionStore['cashu_trusted_mint']              = 'https://mint.example';
		$this->optionStore['cashu_review_earliest_show']      = time() - 60;
		$this->optionStore['cashu_review_dismissed_forever']  = true;

		$this->assertSame( 0, $this->runAdminNotices() );
	}

	public function test_missing_woocommerce_queues_dependency_notice(): void {
		$count = 0;
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$count ): bool {
				if ( 'admin_notices' === $hook ) {
					++$count;
				}
				return true;
			}
		);
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => 'https://shop/wp-admin/' . $p );
		$this->optionStore['cashu_lightning_address'] = 'me@example.com';
		$this->optionStore['cashu_trusted_mint']      = 'https://mint.example';
		$this->optionStore['cashu_review_earliest_show'] = time() + 600;

		CashuWCPlugin::instance()->registerAdminNotices();

		$this->assertSame( 1, $count );
	}

	// ── order-screen render filters ──────────────────────────────────────

	public function test_thankyou_status_section_maps_status_to_description(): void {
		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		ob_start();
		CashuWCPlugin::orderStatusThankYouPage( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Payment settled', $html );
		$this->assertStringContainsString( 'woocommerce-order-payment-status', $html );
	}

	public function test_thankyou_status_section_ucfirsts_unknown_statuses(): void {
		$order = $this->mockOrder( 42 );
		$order->shouldReceive( 'get_status' )->andReturn( 'custom-status' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		ob_start();
		CashuWCPlugin::orderStatusThankYouPage( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Custom-status', $html );
	}

	public function test_order_totals_row_added_only_for_paid_cashu_orders(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$plugin = CashuWCPlugin::instance();

		$foreign = $this->mockOrder( 1 );
		$foreign->shouldReceive( 'get_payment_method' )->andReturn( 'stripe' );
		$this->assertSame( array(), $plugin->addCashuOrderItemTotals( array(), $foreign ) );

		$unpaid = $this->mockOrder( 2, array( '_cashu_melt_total' => '5061' ) );
		$unpaid->shouldReceive( 'is_paid' )->andReturn( false );
		$this->assertSame( array(), $plugin->addCashuOrderItemTotals( array(), $unpaid ) );

		$paid = $this->mockOrder( 3, array( '_cashu_melt_total' => '5061' ) );
		$paid->shouldReceive( 'is_paid' )->andReturn( true );
		$totals = $plugin->addCashuOrderItemTotals( array(), $paid );
		$this->assertArrayHasKey( 'cashu_expected_amount', $totals );
		$this->assertStringContainsString( '5,061', $totals['cashu_expected_amount']['value'] );
	}

	public function test_plugin_action_links_prepend_settings_logs_and_docs(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => 'https://shop/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		Functions\when( 'sanitize_file_name' )->returnArg( 1 );

		$links = CashuWCPlugin::instance()->addPluginActionLinks( array( '<a href="#">Deactivate</a>' ) );

		$this->assertCount( 4, $links );
		$this->assertStringContainsString( 'tab=cashu_settings', $links[0] );
		$this->assertStringContainsString( 'Debug log', $links[1] );
		$this->assertStringContainsString( 'cashu.space', $links[2] );
	}
}
