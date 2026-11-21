<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Webhook;

use Illuminate\Contracts\Cache\Repository;
use Psr\Clock\ClockInterface;
use SelcomDevelopers\Selcom\Exception\ReplayStoreException;
use SelcomDevelopers\Selcom\SystemClock;
use SelcomDevelopers\Selcom\Webhook\ReplayStore;

/**
 * A replay store on a Laravel cache store, so the guard holds across PHP-FPM requests and workers.
 *
 * The check is one atomic `add`: the first delivery of a digest adds the key, every later one
 * until `$keepUntil` finds it. The verifier passes max(now, signed timestamp) + window as
 * `$keepUntil`; the entry is kept through that second. Use a shared store (redis, database,
 * memcached, file), not "array".
 *
 * If the cache itself fails, a ReplayStoreException is thrown (not a verdict): the webhook
 * controller answers 5xx so the gateway delivers again.
 */
final class CacheReplayStore implements ReplayStore, ForgetsDigests
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'selcom:webhook:',
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function forget(string $digest): void
    {
        try {
            $this->cache->forget($this->prefix . hash('sha256', $digest));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function seen(string $digest, int $keepUntil): bool
    {
        // Live through $keepUntil itself, so keep it one second past.
        $ttl = max(1, $keepUntil - $this->clock->now()->getTimestamp() + 1);

        try {
            // add() returns true only when the key did not exist: that is the first sighting.
            return !$this->cache->add($this->prefix . hash('sha256', $digest), 1, $ttl);
        } catch (\Throwable $e) {
            throw new ReplayStoreException('The replay store failed.', 0, $e);
        }
    }
}
