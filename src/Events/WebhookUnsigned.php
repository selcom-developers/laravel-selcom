<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

/**
 * No Digest header (UNSIGNED). Nothing to check: order-status confirmation is the only protection. Acknowledged with 200.
 */
final class WebhookUnsigned extends WebhookEvent
{
}
