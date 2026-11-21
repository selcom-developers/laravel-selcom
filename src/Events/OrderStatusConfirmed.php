<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

use SelcomDevelopers\Selcom\Result\OrderStatusResult;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;

/**
 * The queued confirmation asked the gateway for the order's status and applied the answer
 * through the final-state guard. It is INFORMATION about one check, and it can fire several
 * times for one order (every re-check while the order is pending, and again after a redelivery).
 *
 * Do not fulfil on it. Fulfil on {@see OrderSucceeded}, which the guard fires once, at the
 * transition to success. `outcome` is the guard's outcome ("applied", "recorded", "flagged",
 * "ignored"), or null when no orders table is in use (then keep your own compare-and-set, keyed
 * on the order, in your listener). A FAILED here describes the latest attempt only: cancel the
 * order before you close it as failed.
 *
 * The result and payload are carried redacted when this event is serialised.
 */
final class OrderStatusConfirmed
{
    use SerializesRedacted;

    public function __construct(
        public readonly string $orderId,
        public readonly OrderStatusResult $result,
        public readonly ?string $outcome = null,
        public readonly ?WebhookPayload $payload = null,
    ) {
    }
}
