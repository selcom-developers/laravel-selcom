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
 * payment attempt hits the index. If the second one carries a stronger verdict than the stored
 * row (a VALID delivery after an UNSIGNED one), the row is upgraded to it, so the first delivery
 * to arrive never decides the verdict.
 */
final class DatabaseWebhookStore implements WebhookStore
{
    /**
     * @param class-string<SelcomWebhookDelivery> $model
     */
    public function __construct(private readonly string $model = SelcomWebhookDelivery::class)
    {
    }

    public function store(WebhookPayload $payload, WebhookVerdict $verdict, array $redactedHeaders, string $redactedBody): StoreOutcome
    {
        $attributes = [
            'verdict' => $verdict->value,
            'payment_status' => $payload->paymentStatus,
            'body' => $redactedBody,
            'headers' => $redactedHeaders,
            'received_at' => now(),
        ];

        try {
            $this->model::query()->create([
                'dedupe_key' => $payload->dedupeKey(),
                'order_id' => $payload->orderId,
                'transid' => $payload->transid,
            ] + $attributes);
        } catch (UniqueConstraintViolationException $e) {
            $key = $payload->dedupeKey();
            $existing = $key === null ? null : $this->model::query()->where('dedupe_key', $key)->first();
            if ($existing === null) {
                throw $e;
            }
            $stored = WebhookVerdict::tryFrom($existing->verdict);
            if ($stored !== null && StoreOutcome::strength($verdict) <= StoreOutcome::strength($stored)) {
                return StoreOutcome::Duplicate;
            }
            $existing->forceFill($attributes + ['processed_at' => null])->save();

            return StoreOutcome::Upgraded;
        }

        return StoreOutcome::Stored;
    }
}
