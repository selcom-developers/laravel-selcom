<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Webhook;

use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;

/**
 * What a WebhookStore did with a delivery.
 */
enum StoreOutcome
{
    /** A new delivery was stored. */
    case Stored;

    /** The same payment attempt was already stored with a weaker verdict; the row now carries the stronger one. */
    case Upgraded;

    /** The same payment attempt was already stored with an equal or stronger verdict. Nothing changed. */
    case Duplicate;

    /** True when the delivery is new information: the order-status confirmation should be queued. */
    public function isNew(): bool
    {
        return $this !== self::Duplicate;
    }

    /**
     * How much a verdict vouches for a delivery: VALID over UNSIGNED over the rest (which only get
     * stored in report-only mode).
     */
    public static function strength(WebhookVerdict $verdict): int
    {
        return match ($verdict) {
            WebhookVerdict::Valid => 3,
            WebhookVerdict::Unsigned => 2,
            default => 1,
        };
    }
}
