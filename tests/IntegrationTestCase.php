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
		parent::tearDown();
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
		$order->shouldReceive( 'save' )->andReturn( $id );

		return $order;
	}
}
