<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

use SelcomDevelopers\Selcom\Result\OrderStatusResult;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;

/**
 * The queued confirmation of a webhook: checkout.order-status was called for the order. Act on
 * this result (status, amount), not on the webhook. A FAILED here describes the latest attempt
 * only: cancel the order before you close it as failed.
 */
final class OrderStatusConfirmed
{
    public function __construct(
        public readonly string $orderId,
        public readonly OrderStatusResult $result,
        public readonly ?WebhookPayload $payload = null,
    ) {
    }
}
