<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;

/**
 * Base of every webhook event. A webhook is a hint, not proof: confirm with checkout.order-status
 * before you fulfil anything.
 *
 * `headers` and `redactedBody` are safe to log (Authorization and Digest are masked, personal data
 * is masked). `payload` carries the parsed fields as received, so treat it as personal data.
 */
abstract class WebhookEvent
{
    /**
     * @param array<string, mixed> $headers headers with Authorization and Digest redacted
     */
    public function __construct(
        public readonly WebhookVerdict $verdict,
        public readonly ?WebhookPayload $payload,
        public readonly array $headers,
        public readonly string $redactedBody,
    ) {
    }
}
