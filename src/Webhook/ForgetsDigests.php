<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Webhook;

/**
 * A replay store that can drop a digest again. The webhook controller uses it when it has to
 * answer 5xx after verification, so that the gateway's identical redelivery is processed instead
 * of being treated as already handled.
 */
interface ForgetsDigests
{
    public function forget(string $digest): void;
}
