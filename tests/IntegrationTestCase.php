<?php

declare(strict_types=1);

namespace Cashu\WC\Tests;

use Brain\Monkey;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use WC_Order;

/**
 * Base class for tests that need WP function stubs (via Brain\Monkey) and/or
 * WC_Order mocks (via Mockery). Pure-helper tests under tests/Helpers/ don't
 * need this — extend PHPUnit\Framework\TestCase directly there.
 */
abstract class IntegrationTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Wire a Mockery $wpdb global into $GLOBALS so OrderLock (which calls
	 * $wpdb->prepare/query/get_var directly) can run. INSERT IGNORE on a
	 * fresh lock returns 1 row affected; pass $insertResult=0 to simulate
	 * "another process already holds the lock", and $existingExpiry to
	 * simulate the value SELECT'd back.
	 *
	 * Tests that need fancier $wpdb behaviour should mock it themselves
	 * directly; this helper covers the common acquire/release fast paths.
	 */
	protected function setUpFakeWpdb( int $insertResult = 1, ?int $existingExpiry = null ): MockInterface {
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( string $sql, ...$args ): string {
					return $sql . '|' . implode( '|', array_map( 'strval', $args ) );
				}
			);
		$wpdb->shouldReceive( 'query' )->andReturn( $insertResult );
		$wpdb->shouldReceive( 'get_var' )->andReturn(
			null === $existingExpiry ? null : (string) $existingExpiry
		);

		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	/**
	 * Build a Mockery WC_Order pre-wired with meta accessors.
	 *
	 * Meta keys not in $meta return ''. update_meta_data writes through to the
	 * same array so subsequent get_meta calls see fresh values — this matches
	 * the real WC_Order behaviour pre-save() and is what most controller code
	 * relies on.
	 */
	protected function mockOrder( int $id, array $meta = [] ): MockInterface {
		$state = $meta;

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		// Default to the cashu gateway so load_order() doesn't reject the
		// order; tests covering wrong-gateway should override this.
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'cashu_default' )->byDefault();
		$order->shouldReceive( 'read_meta_data' )->andReturn( null )->byDefault();
		$order->shouldReceive( 'save' )->andReturn( $id )->byDefault();
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( string $key, bool $single = true ) use ( &$state ) {
				return $state[ $key ] ?? '';
			}
		);
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ) use ( &$state ): void {
				$state[ $key ] = $value;
			}
		);
		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing(
			function ( string $key ) use ( &$state ): void {
				unset( $state[ $key ] );
			}
		);

		return $order;
	}
}
