<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Webhook;

use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;

/**
 * Where a delivery is stored before it is acknowledged. Bind your own implementation to keep
 * deliveries somewhere else.
 */
interface WebhookStore
{
    /**
     * Store the delivery. A duplicate of one already stored (same order_id and transid) is
     * Duplicate, unless this delivery's verdict is stronger than the stored one (VALID over
     * UNSIGNED): then the row is upgraded and the outcome is Upgraded, so confirmation is queued
     * again. Throw when storing failed: the controller answers 500 so the gateway tries again.
     *
     * @param array<string, mixed> $redactedHeaders Authorization and Digest already masked
     * @param string $redactedBody personal data already masked
     */
    public function store(
        WebhookPayload $payload,
        WebhookVerdict $verdict,
        array $redactedHeaders,
        string $redactedBody,
    ): StoreOutcome;
}
