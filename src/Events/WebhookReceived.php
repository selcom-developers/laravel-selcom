<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

/**
 * Fired for every delivery that reaches the route, whatever the verdict, before anything else happens.
 */
final class WebhookReceived extends WebhookEvent
{
}
