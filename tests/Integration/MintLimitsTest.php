<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Integration;

use Brain\Monkey\Functions;
use Cashu\WC\Helpers\MintLimits;
use Cashu\WC\Tests\IntegrationTestCase;

final class MintLimitsTest extends IntegrationTestCase {

	private array $optionStore = array();

	protected function setUp(): void {
		parent::setUp();
		$this->optionStore = array();

		Functions\when( '__' )->returnArg( 1 );
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
		Functions\when( 'absint' )->alias( static fn ( $n ) => abs( (int) $n ) );
		Functions\when( 'untrailingslashit' )->alias( static fn ( $url ) => rtrim( (string) $url, '/' ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $r ) => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : ''
		);
	}

	// ── extract_bolt11_sat_range: the falsy-vs-absent contract ──────────

	public function test_extract_treats_zero_min_and_absent_max_as_unbounded(): void {
		// NUT-23's own example advertises "min_amount": 0 — it must read as
		// "no minimum", and a missing max_amount as "no maximum".
		$nuts = array(
			'4' => array(
				'methods' => array(
					array(
						'method'     => 'bolt11',
						'unit'       => 'sat',
						'min_amount' => 0,
					),
				),
			),
		);

		$range = MintLimits::extract_bolt11_sat_range( $nuts, '4' );

		$this->assertSame( array( 'min' => null, 'max' => null ), $range );
	}

	public function test_extract_treats_zero_max_as_unbounded_not_max_zero(): void {
		// A malformed "max_amount": 0 must not be read as "max 0 sats",
		// which would block every checkout.
		$nuts = array(
			'5' => array(
				'methods' => array(
					array(
						'method'     => 'bolt11',
						'unit'       => 'sat',
						'min_amount' => 100,
						'max_amount' => 0,
					),
				),
			),
		);

		$range = MintLimits::extract_bolt11_sat_range( $nuts, '5' );

		$this->assertSame( array( 'min' => 100, 'max' => null ), $range );
	}

	public function test_extract_reads_positive_bounds(): void {
		$nuts = array(
			'5' => array(
				'methods' => array(
					array(
						'method'     => 'bolt11',
						'unit'       => 'sat',
						'min_amount' => 100,
						'max_amount' => 10000,
					),
				),
			),
		);

		$this->assertSame(
			array( 'min' => 100, 'max' => 10000 ),
			MintLimits::extract_bolt11_sat_range( $nuts, '5' )
		);
	}

	public function test_extract_returns_null_when_bolt11_sat_method_missing(): void {
		$nuts = array(
			'4' => array(
				'methods' => array(
					array(
						'method' => 'bolt12',
						'unit'   => 'sat',
					),
				),
			),
		);

		$this->assertNull( MintLimits::extract_bolt11_sat_range( $nuts, '4' ) );
		$this->assertNull( MintLimits::extract_bolt11_sat_range( $nuts, '5' ) );
	}

	public function test_extract_returns_null_when_nut_disabled(): void {
		$nuts = array(
			'4' => array(
				'disabled' => true,
				'methods'  => array(
					array(
						'method' => 'bolt11',
						'unit'   => 'sat',
					),
				),
			),
		);

		$this->assertNull( MintLimits::extract_bolt11_sat_range( $nuts, '4' ) );
	}

	// ── lnurl_range_sats: msat conversion rounds conservatively ─────────

	public function test_lnurl_range_rounds_min_up_and_max_down(): void {
		$range = MintLimits::lnurl_range_sats(
			array(
				'minSendable' => 1500,      // 1.5 sat → min 2
				'maxSendable' => 99999999,  // 99,999.999 sat → max 99,999
			)
		);

		$this->assertSame( array( 'min' => 2, 'max' => 99999 ), $range );
	}

	public function test_lnurl_range_treats_non_numeric_as_unbounded(): void {
		$range = MintLimits::lnurl_range_sats( array( 'minSendable' => 'soon', 'maxSendable' => null ) );

		$this->assertSame( array( 'min' => null, 'max' => null ), $range );
	}

	// ── allows(): fail open unless fresh data clearly excludes ──────────

	/** Store a realistic snapshot + matching options. */
	private function seedSnapshot( array $mint_overrides = array(), array $lnurl_overrides = array() ): void {
		$this->optionStore['cashu_trusted_mint']      = 'https://mint.example/Bitcoin';
		$this->optionStore['cashu_lightning_address'] = 'me@example.com';
		$this->optionStore[ MintLimits::OPTION ]      = array(
			'mint'  => array_merge(
				array(
					'url'        => 'https://mint.example/Bitcoin',
					'mint_min'   => null,
					'mint_max'   => null,
					'melt_min'   => 100,
					'melt_max'   => 100000,
					'fetched_at' => time(),
				),
				$mint_overrides
			),
			'lnurl' => array_merge(
				array(
					'address'    => 'me@example.com',
					'min'        => 1,
					'max'        => 500000,
					'fetched_at' => time(),
				),
				$lnurl_overrides
			),
		);
	}

	public function test_allows_when_no_snapshot_exists(): void {
		$this->optionStore['cashu_trusted_mint'] = 'https://mint.example/Bitcoin';

		$this->assertTrue( MintLimits::allows( 12345 ) );
	}

	public function test_allows_in_range_amount(): void {
		$this->seedSnapshot();

		$this->assertTrue( MintLimits::allows( 5000 ) );
	}

	public function test_rejects_below_melt_min(): void {
		$this->seedSnapshot();

		$this->assertFalse( MintLimits::allows( 99 ) );
	}

	public function test_rejects_above_melt_max(): void {
		$this->seedSnapshot();

		$this->assertFalse( MintLimits::allows( 100001 ) );
	}

	public function test_rejects_above_lnurl_max(): void {
		$this->seedSnapshot( array( 'melt_max' => null ) );

		$this->assertFalse( MintLimits::allows( 500001 ) );
	}

	public function test_mint_leg_max_applies_fee_headroom(): void {
		// Order total of 9,900 sat fits a 10,000 mint_max on paper, but the
		// customer leg pays total + fees: 9,900 × 1.02 + 4 ≈ 10,102 > max.
		$this->seedSnapshot( array( 'mint_max' => 10000, 'melt_max' => null ) );

		$this->assertFalse( MintLimits::allows( 9900 ) );
		$this->assertTrue( MintLimits::allows( 9700 ) );
	}

	public function test_mint_min_checked_against_bare_total(): void {
		$this->seedSnapshot( array( 'mint_min' => 1000, 'melt_min' => null ) );

		$this->assertFalse( MintLimits::allows( 999 ) );
		$this->assertTrue( MintLimits::allows( 1000 ) );
	}

	public function test_allows_when_snapshot_is_stale(): void {
		$this->seedSnapshot(
			array( 'fetched_at' => time() - MintLimits::STALE_AFTER_SECS - 10 ),
			array( 'fetched_at' => time() - MintLimits::STALE_AFTER_SECS - 10 )
		);

		$this->assertTrue( MintLimits::allows( 1 ) );
	}

	public function test_allows_when_snapshot_belongs_to_different_mint(): void {
		$this->seedSnapshot( array( 'url' => 'https://other-mint.example' ), array( 'address' => 'other@example.com' ) );

		$this->assertTrue( MintLimits::allows( 1 ) );
	}

	public function test_mint_url_match_is_normalised(): void {
		// Same mint re-saved with different casing / trailing slash must
		// still count as the same source.
		$this->seedSnapshot();
		$this->optionStore['cashu_trusted_mint'] = 'https://MINT.example/Bitcoin/';

		$this->assertFalse( MintLimits::allows( 99 ) );
	}

	public function test_allows_zero_or_negative_amounts(): void {
		$this->seedSnapshot();

		$this->assertTrue( MintLimits::allows( 0 ) );
	}

	// ── refresh(): throttle, force, and fail-safe behaviour ─────────────

	public function test_refresh_skips_fetch_when_snapshot_fresh(): void {
		$this->seedSnapshot();
		Functions\expect( 'wp_remote_get' )->never();

		MintLimits::refresh();

		$this->assertSame( 100, $this->optionStore[ MintLimits::OPTION ]['mint']['melt_min'] );
	}

	public function test_refresh_force_refetches_despite_fresh_snapshot(): void {
		$this->seedSnapshot();
		$info = array(
			'nuts' => array(
				'4' => array( 'methods' => array( array( 'method' => 'bolt11', 'unit' => 'sat' ) ) ),
				'5' => array(
					'methods' => array(
						array(
							'method'     => 'bolt11',
							'unit'       => 'sat',
							'min_amount' => 500,
							'max_amount' => 50000,
						),
					),
				),
			),
		);
		Functions\expect( 'wp_remote_get' )
			->twice() // /v1/info + lnurlp metadata
			->andReturnUsing(
				static function ( string $url ) use ( $info ): array {
					$body = false !== strpos( $url, '/v1/info' )
						? $info
						: array( 'minSendable' => 1000, 'maxSendable' => 2000000 );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => (string) json_encode( $body ),
					);
				}
			);

		MintLimits::refresh( true );

		$this->assertSame( 500, $this->optionStore[ MintLimits::OPTION ]['mint']['melt_min'] );
		$this->assertSame( 50000, $this->optionStore[ MintLimits::OPTION ]['mint']['melt_max'] );
		$this->assertSame( 2000, $this->optionStore[ MintLimits::OPTION ]['lnurl']['max'] );
	}

	public function test_refresh_keeps_previous_snapshot_when_fetch_fails(): void {
		$this->seedSnapshot(
			array( 'fetched_at' => time() - 2 * MintLimits::REFRESH_THROTTLE_SECS ),
			array( 'fetched_at' => time() - 2 * MintLimits::REFRESH_THROTTLE_SECS )
		);
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturn( new \WP_Error( 'http_request_failed', 'connect timeout' ) );

		MintLimits::refresh();

		// Stale-but-present beats erased: the block survives untouched.
		$this->assertSame( 100, $this->optionStore[ MintLimits::OPTION ]['mint']['melt_min'] );
		$this->assertSame( 500000, $this->optionStore[ MintLimits::OPTION ]['lnurl']['max'] );
	}

	public function test_refresh_does_nothing_when_unconfigured(): void {
		Functions\expect( 'wp_remote_get' )->never();

		MintLimits::refresh( true );

		$this->assertArrayNotHasKey( MintLimits::OPTION, $this->optionStore );
	}
}
