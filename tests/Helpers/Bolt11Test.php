<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Helpers;

use Cashu\WC\Helpers\Bolt11;
use PHPUnit\Framework\TestCase;

final class Bolt11Test extends TestCase {

	/**
	 * Reference invoice + expected payment_hash from the BOLT-11 spec test
	 * vectors. Anchors the bech32 + tag parsing against a known-good input.
	 */
	public function test_extracts_payment_hash_from_spec_vector(): void {
		$invoice = 'lnbc1pvjluezpp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypqdpl2pkx2ctnv5sxxmmwwd5kgetjypeh2ursdae8g6twvus8g6rfwvs8qun0dfjkxaq8rkx3yf5tcsyz3d73gafnh3cax9rn449d9p5uxz9ezhhypd0elx87sjle52x86fux2ypatgddc6k63n7erqz25le42c4u4ecky03ylcqca784w';
		$this->assertSame(
			'0001020304050607080900010203040506070809000102030405060708090102',
			Bolt11::paymentHash( $invoice )
		);
	}

	public function test_returns_null_on_garbage(): void {
		$this->assertNull( Bolt11::paymentHash( '' ) );
		$this->assertNull( Bolt11::paymentHash( 'not-a-bolt11' ) );
		$this->assertNull( Bolt11::paymentHash( 'lnbc' ) );
	}

	public function test_handles_uppercase_input(): void {
		$invoice = strtoupper(
			'lnbc1pvjluezpp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypqdpl2pkx2ctnv5sxxmmwwd5kgetjypeh2ursdae8g6twvus8g6rfwvs8qun0dfjkxaq8rkx3yf5tcsyz3d73gafnh3cax9rn449d9p5uxz9ezhhypd0elx87sjle52x86fux2ypatgddc6k63n7erqz25le42c4u4ecky03ylcqca784w'
		);
		$this->assertSame(
			'0001020304050607080900010203040506070809000102030405060708090102',
			Bolt11::paymentHash( $invoice )
		);
	}
}
