<?php

declare(strict_types=1);

namespace Cashu\WC\Tests\Helpers;

use Cashu\WC\Helpers\CashuHelper;
use PHPUnit\Framework\TestCase;

/**
 * The preimage is proof-of-payment; order notes must only ever carry the
 * truncated form (full value lives in _cashu_payment_preimage meta).
 */
final class RedactPreimageTest extends TestCase {

	public function test_64_char_preimage_is_truncated(): void {
		$preimage = str_repeat( 'ab', 32 ); // 64 hex chars
		$redacted = CashuHelper::redactPreimage( $preimage );

		$this->assertSame( 'abababab…abababab', $redacted );
		$this->assertStringNotContainsString( $preimage, $redacted );
	}

	public function test_short_values_pass_through(): void {
		$this->assertSame( '', CashuHelper::redactPreimage( '' ) );
		$this->assertSame( '', CashuHelper::redactPreimage( null ) );
		$this->assertSame( 'deadbeef', CashuHelper::redactPreimage( 'deadbeef' ) );
	}
}
