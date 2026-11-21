<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\Selcom\Result\Result;

/**
 * An order moved to `success`. Fired by the final-state guard exactly once per order, at the
 * transition: success is final, so a later poll, webhook confirmation, job retry or redelivery
 * finds the order already final and fires nothing. Key fulfilment on this event, never on a
 * webhook or on OrderStatusConfirmed.
 *
 * The result is carried redacted when this event is serialised.
 */
final class OrderSucceeded
{
    use SerializesRedacted;

    public function __construct(
        public readonly SelcomOrder $order,
        public readonly Result $result,
    ) {
    }
}
