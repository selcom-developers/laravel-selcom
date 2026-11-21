<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

use SelcomDevelopers\LaravelSelcom\Models\SelcomPayout;
use SelcomDevelopers\Selcom\Result\Result;

/**
 * A payout moved to `success`. Fired by the final-state guard exactly once per payout, at the
 * transition (see OrderSucceeded).
 */
final class PayoutSucceeded
{
    use SerializesRedacted;

    public function __construct(
        public readonly SelcomPayout $payout,
        public readonly Result $result,
    ) {
    }
}
