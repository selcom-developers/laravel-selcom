<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

/**
 * The signature checked out (VALID). Delivery is acknowledged with 200 and, once stored, should be confirmed with order-status.
 */
final class WebhookValid extends WebhookEvent
{
}
