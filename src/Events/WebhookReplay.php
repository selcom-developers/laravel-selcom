<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

/**
 * The same digest was seen inside the window (REPLAY). Acknowledged with 200 and not processed again.
 */
final class WebhookReplay extends WebhookEvent
{
}
