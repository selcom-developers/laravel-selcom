<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Webhook;

use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;

/**
 * Stores nothing: every delivery counts as new (Stored). Only the replay guard (same digest inside the
 * window) deduplicates. Listen to the events and store the delivery yourself, or switch
 * `selcom.webhook.store` to "database".
 */
final class NullWebhookStore implements WebhookStore
{
    public function store(WebhookPayload $payload, WebhookVerdict $verdict, array $redactedHeaders, string $redactedBody): StoreOutcome
    {
        return StoreOutcome::Stored;
    }
}
