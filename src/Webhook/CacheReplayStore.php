<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Webhook;

use Illuminate\Contracts\Cache\Repository;
use SelcomDevelopers\Selcom\Webhook\ReplayStore;

/**
 * A replay store on a Laravel cache store, so the guard holds across PHP-FPM requests and workers.
 *
 * The check is one atomic `add`: the first delivery of a digest adds the key, every later one
 * within the window finds it. Use a shared store (redis, database, memcached, file), not "array".
 *
 * Expiry: a digest is remembered until max(now, signed timestamp) + window. The store does not see
 * the timestamp, and the verifier accepts one up to a window either side of now, so the store keeps
 * each digest for twice the window, which always covers it.
 */
final class CacheReplayStore implements ReplayStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'selcom:webhook:',
    ) {
    }

    public function seen(string $digest, int $ttlSeconds): bool
    {
        // add() returns true only when the key did not exist: that is the first sighting.
        return !$this->cache->add($this->prefix . hash('sha256', $digest), 1, $ttlSeconds * 2);
    }
}
