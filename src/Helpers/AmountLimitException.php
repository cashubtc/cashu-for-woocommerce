<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * A payment amount fell outside the limits advertised (or enforced) by the
 * mint or the merchant's LNURL service. Distinct from generic transport /
 * mint errors so the gateway can show an accurate "amount out of range"
 * message instead of "reload and try again", and force-refresh the cached
 * limits snapshot.
 */
final class AmountLimitException extends \RuntimeException {
}
