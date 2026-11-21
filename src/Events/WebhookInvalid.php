<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

/**
 * The signature did not match, or the headers or body were malformed (INVALID). Answered with 401 and not processed (unless the route runs in report_only mode).
 */
final class WebhookInvalid extends WebhookEvent
{
}
