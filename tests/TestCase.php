<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests;

use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase as Orchestra;
use SelcomDevelopers\LaravelSelcom\SelcomServiceProvider;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Vectors;

abstract class TestCase extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SelcomServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        \assert($config instanceof Repository);

        $config->set('database.default', 'testing');
        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');

        $config->set('selcom.environment', 'sandbox');
        $config->set('selcom.key', 'test-key-0001');
        $config->set('selcom.secret', 'test-secret-0001');
        $config->set('selcom.vendor', 'TILL123');
        $config->set('selcom.pin', '0000');
        // The sample secret printed in Selcom's public docs, used to sign the webhook vectors.
        $config->set('selcom.webhook.secret', Vectors::webhookSecret());
    }

    /**
     * Run the published migration stubs, as an app that published them would.
     */
    protected function runMigrationStubs(): void
    {
        foreach (['orders', 'payouts', 'webhook_deliveries'] as $name) {
            $migration = require __DIR__ . '/../database/migrations/create_selcom_' . $name . '_table.php.stub';
            $migration->up();
        }
    }
}
