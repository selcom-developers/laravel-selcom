<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

use SelcomDevelopers\LaravelSelcom\Support\Redacted;
use SelcomDevelopers\Selcom\Result\Result;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;

/**
 * Serialises an event with its results and webhook payloads redacted, so queued listeners and
 * broadcasts never carry raw bodies or personal data. In the running process the event holds the
 * originals; only the serialised copy is masked.
 */
trait SerializesRedacted
{
    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = [];
        foreach (get_object_vars($this) as $name => $value) {
            $data[$name] = match (true) {
                $value instanceof Result => Redacted::result($value),
                $value instanceof WebhookPayload => Redacted::payload($value),
                default => $value,
            };
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $name => $value) {
            $this->{$name} = $value;
        }
    }
}
