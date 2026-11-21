<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

/**
 * The timestamp was outside the window (STALE). Answered with 401 and not processed (unless the route runs in report_only mode).
 */
final class WebhookStale extends WebhookEvent
{
}
