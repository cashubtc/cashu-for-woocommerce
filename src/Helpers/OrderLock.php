<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * Atomic per-order advisory lock backed by the UNIQUE constraint on
 * `wp_options.option_name`. Used to serialise concurrent REST hits on
 * the same order — two wallets POSTing /pay/{id}/{key} at once, or two
 * browser tabs both running setup on /checkout/order-pay/{id}.
 *
 * `add_option()` uses `INSERT ... ON DUPLICATE KEY UPDATE` under the
 * hood, so it isn't a CAS primitive on its own. We hit `$wpdb` directly
 * with `INSERT IGNORE` for the atomic semantic this lock needs.
 *
 * Locks expire after `$ttl_seconds`. A crashed PHP process is self-
 * clearing once the TTL elapses.
 *
 * Per-acquirer tokens: acquire() returns a random token that release()
 * checks before deleting. A caller that no longer holds the lock (TTL
 * expired, another process refreshed it) cannot accidentally release
 * someone else's critical section. Pass the token through to release().
 * The legacy unconditional `release($id, $scope)` form is still
 * accepted for back-compat and admin tooling that doesn't hold a token.
 */
final class OrderLock {

	private const PREFIX = 'cashu_wc_lock_';

	/**
	 * Try to take the lock. Returns the per-acquirer token on success
	 * (pass it to release() so the conditional DELETE can prove ownership),
	 * or null when another process holds an unexpired lock.
	 */
	public static function acquire( int $order_id, string $scope, int $ttl_seconds ): ?string {
		global $wpdb;

		$key        = self::key( $order_id, $scope );
		$expires_at = time() + max( 1, $ttl_seconds );
		$token      = self::generate_token();
		$value      = self::pack( $token, $expires_at );

		// Direct $wpdb is required: we need INSERT IGNORE's atomic
		// "succeed only if the row does not exist" semantic for the lock.
		// add_option() is INSERT ... ON DUPLICATE KEY UPDATE — not a CAS
		// primitive — and an object cache would mask the conflict we are
		// relying on to fail loudly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				$value,
				'no'
			)
		);
		if ( 1 === (int) $inserted ) {
			return $token;
		}

		// Read the live row, not a cache — a stale object-cache value
		// would let us delete a lock another process already refreshed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$key
			)
		);
		if ( null === $existing ) {
			// Disappeared between INSERT IGNORE and SELECT — retry once.
			return self::insert_lock( $key, $value ) ? $token : null;
		}
		if ( self::parse_expiry( (string) $existing ) > time() ) {
			return null;
		}

		// Stale lock — only clear the row we read; if another process
		// raced us to refresh it, the conditional DELETE leaves theirs
		// intact and we bail out.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				(string) $existing
			)
		);
		if ( 1 !== (int) $deleted ) {
			return null;
		}

		return self::insert_lock( $key, $value ) ? $token : null;
	}

	/**
	 * Release a lock we hold. With $token, the DELETE is conditional on
	 * the stored value still matching ours — protects against releasing
	 * a lock another process refreshed after our TTL expired. Without
	 * $token, the DELETE is unconditional (back-compat for admin tooling
	 * and pre-token callers; new code should always pass the token).
	 */
	public static function release( int $order_id, string $scope, ?string $token = null ): void {
		global $wpdb;
		$key = self::key( $order_id, $scope );

		if ( null === $token ) {
			// Direct delete: the lock row must not survive in any object cache
			// after release, or the next acquire() would falsely see it held.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s",
					$key
				)
			);
			return;
		}

		// Conditional delete: only remove our row if the stored token still
		// matches ours. esc_like keeps the hex token literal in the LIKE.
		$pattern = $wpdb->esc_like( $token ) . '|%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				$key,
				$pattern
			)
		);
	}

	/**
	 * Spin-wait for an in-flight lock to release. Returns true if the
	 * lock was released (or expired) within the budget. Callers use this
	 * when they want the *result* of the in-flight work instead of redoing
	 * it themselves.
	 */
	public static function wait_for_release( int $order_id, string $scope, int $max_wait_seconds ): bool {
		$deadline = time() + max( 0, $max_wait_seconds );
		do {
			if ( ! self::is_held( $order_id, $scope ) ) {
				return true;
			}
			usleep( 250000 );
		} while ( time() < $deadline );

		return ! self::is_held( $order_id, $scope );
	}

	private static function insert_lock( string $key, string $value ): bool {
		global $wpdb;
		// Same INSERT IGNORE atomicity requirement as acquire().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				$value,
				'no'
			)
		);
		return 1 === (int) $inserted;
	}

	private static function is_held( int $order_id, string $scope ): bool {
		global $wpdb;
		// Spin-wait poll: must see the live row, not a cached value, or
		// the wait would never observe another process releasing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::key( $order_id, $scope )
			)
		);
		if ( null === $value ) {
			return false;
		}
		return self::parse_expiry( (string) $value ) > time();
	}

	private static function key( int $order_id, string $scope ): string {
		$clean = preg_replace( '/[^a-z0-9_]/', '', strtolower( $scope ) );
		if ( '' === (string) $clean ) {
			$clean = 'default';
		}
		return self::PREFIX . $clean . '_' . $order_id;
	}

	/**
	 * 32-char hex token. random_bytes throws on entropy failure (extremely
	 * rare); a thrown exception would propagate up to the caller and the
	 * lock would not be acquired — preferable to falling back to a weak
	 * source that could allow token guessing.
	 */
	private static function generate_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Pack the stored value as `{token}|{expiry}`. Token-first so the
	 * conditional-release LIKE pattern (`{token}|%`) is a prefix match.
	 */
	private static function pack( string $token, int $expires_at ): string {
		return $token . '|' . $expires_at;
	}

	/**
	 * Extract the expiry timestamp from a stored value. Handles both the
	 * `{token}|{expiry}` form and the legacy `{expiry}` form (rows written
	 * by pre-token plugin versions) so upgrades don't strand existing locks.
	 */
	private static function parse_expiry( string $value ): int {
		$parts = explode( '|', $value, 2 );
		if ( 2 === count( $parts ) ) {
			return (int) $parts[1];
		}
		return (int) $value;
	}
}
