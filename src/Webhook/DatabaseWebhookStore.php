<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Webhook;

use Illuminate\Database\UniqueConstraintViolationException;
use SelcomDevelopers\LaravelSelcom\Models\SelcomWebhookDelivery;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;

/**
 * Stores each delivery in the selcom_webhook_deliveries table (publish the migrations first).
 * The unique `dedupe_key` ("order_id|transid") is the durable guard: a second delivery of the same
 * payment attempt hits the index and is reported as a duplicate.
 */
final class DatabaseWebhookStore implements WebhookStore
{
    /**
     * @param class-string<SelcomWebhookDelivery> $model
     */
    public function __construct(private readonly string $model = SelcomWebhookDelivery::class)
    {
    }

    public function store(WebhookPayload $payload, WebhookVerdict $verdict, array $redactedHeaders, string $redactedBody): bool
    {
        try {
            $this->model::query()->create([
                'dedupe_key' => $payload->dedupeKey(),
                'order_id' => $payload->orderId,
                'transid' => $payload->transid,
                'verdict' => $verdict->value,
                'payment_status' => $payload->paymentStatus,
                'body' => $redactedBody,
                'headers' => $redactedHeaders,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
