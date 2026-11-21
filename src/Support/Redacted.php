<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Support;

use SelcomDevelopers\Selcom\Redaction\Redactor;
use SelcomDevelopers\Selcom\Result\Result;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;

/**
 * Safe-to-store copies of results and webhook payloads, for events that may be serialised onto a
 * queue (a queued listener, a broadcast): personal data is masked, nothing else changes.
 */
final class Redacted
{
    public static function result(Result $result): Result
    {
        $redactor = new Redactor();
        $data = [];
        foreach ($result->data as $entry) {
            $data[] = self::keyed($redactor->redactParams($entry));
        }

        /** @var Result */
        return new ($result::class)(
            $result->operation,
            $result->status,
            $result->resultCode,
            $result->result,
            $result->message,
            $result->reference,
            $result->transid,
            $result->orderId,
            $result->httpStatus,
            $data,
            $redactor->redactJson($result->raw),
        );
    }

    /**
     * @param array<int|string, mixed> $array
     *
     * @return array<string, mixed>
     */
    private static function keyed(array $array): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    public static function payload(WebhookPayload $payload): WebhookPayload
    {
        $fields = self::keyed((new Redactor())->redactParams($payload->fields));
        $phone = $fields['phone'] ?? $fields['msisdn'] ?? null;

        return new WebhookPayload(
            $payload->orderId,
            $payload->transid,
            $payload->reference,
            $payload->result,
            $payload->resultCode,
            $payload->paymentStatus,
            $payload->channel,
            $payload->amountTzs,
            is_string($phone) ? $phone : null,
            $fields,
        );
    }
}
