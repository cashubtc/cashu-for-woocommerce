<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Cashu\WC\CashuWCPlugin;
use Cashu\WC\Helpers\MeltReconciler;
use Cashu\WC\Tests\IntegrationTestCase;

/**
 * Verifies the cron hook + reschedule guard are registered when the
 * plugin bootstraps.
 */
final class CronRegistrationTest extends IntegrationTestCase {

	public function test_reconciler_hook_is_wired_to_class(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		Actions\expectAdded( MeltReconciler::HOOK )
			->once()
			->with( array( MeltReconciler::class, 'reconcile_pending' ) );

		CashuWCPlugin::instance()->run();

		// Brain\Monkey verifies the expectAdded() during tearDown via
		// Mockery::close(); make this explicit to PHPUnit so the test isn't
		// flagged risky for "no assertions".
		$this->assertTrue( true );
	}
}
