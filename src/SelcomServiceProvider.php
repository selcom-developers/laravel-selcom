<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SelcomDevelopers\LaravelSelcom\Console\ReconcileCommand;
use SelcomDevelopers\LaravelSelcom\Console\StatusCommand;
use SelcomDevelopers\LaravelSelcom\Http\Controllers\WebhookController;
use SelcomDevelopers\LaravelSelcom\Logging\LoggingTransport;
use SelcomDevelopers\LaravelSelcom\Models\SelcomWebhookDelivery;
use SelcomDevelopers\LaravelSelcom\Support\ConfigFactory;
use SelcomDevelopers\LaravelSelcom\Support\DigestSignerFactory;
use SelcomDevelopers\LaravelSelcom\Webhook\CacheReplayStore;
use SelcomDevelopers\LaravelSelcom\Webhook\DatabaseWebhookStore;
use SelcomDevelopers\LaravelSelcom\Webhook\NullWebhookStore;
use SelcomDevelopers\LaravelSelcom\Webhook\WebhookStore;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Config;
use SelcomDevelopers\Selcom\Exception\ConfigurationException;
use SelcomDevelopers\Selcom\Redaction\Redactor;
use SelcomDevelopers\Selcom\Signing\DigestSigner;
use SelcomDevelopers\Selcom\SystemClock;
use SelcomDevelopers\Selcom\Transport\Psr18Transport;
use SelcomDevelopers\Selcom\Transport\Transport;
use SelcomDevelopers\Selcom\Webhook\ReplayStore;
use SelcomDevelopers\Selcom\Webhook\WebhookVerifier;

/**
 * Binds the selcom-php pieces into the container. Every binding is a plain singleton on the
 * container, so you can replace any layer by binding your own in a service provider that boots
 * after this one (or with `$this->app->extend()`):
 *
 *   Config, ClockInterface, DigestSignerFactory, DigestSigner, Redactor, Transport,
 *   ReplayStore, WebhookVerifier, WebhookStore, Client (also available as "selcom").
 *
 * Nothing is resolved at boot, so `config:cache` and `package:discover` work without credentials;
 * a missing environment or credential is an error the first time the client is needed.
 */
final class SelcomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/selcom.php', 'selcom');

        $this->app->singleton(Config::class, static function (Application $app): Config {
            /** @var array<string, mixed> $settings */
            $settings = $app->make(ConfigRepository::class)->get('selcom', []);

            return ConfigFactory::make($settings);
        });

        $this->app->bindIf(ClockInterface::class, static fn (): ClockInterface => new SystemClock());
        $this->app->singleton(DigestSignerFactory::class, static fn (): DigestSignerFactory => new DigestSignerFactory());
        $this->app->singleton(
            DigestSigner::class,
            static fn (Application $app): DigestSigner => $app->make(DigestSignerFactory::class)($app->make(Config::class)->credentials),
        );
        $this->app->singleton(Redactor::class, static fn (): Redactor => new Redactor());
        $this->app->singleton(Transport::class, static fn (): Transport => Psr18Transport::discover());

        $this->app->singleton(Client::class, function (Application $app): Client {
            $transport = $app->make(Transport::class);
            $config = $app->make(ConfigRepository::class);

            $channel = $config->get('selcom.logging.channel');
            if (is_string($channel) && $channel !== '') {
                $level = $config->get('selcom.logging.level', 'info');
                $transport = new LoggingTransport(
                    $transport,
                    $app->make('log')->channel($channel),
                    $app->make(Redactor::class),
                    is_string($level) ? $level : 'info',
                );
            }

            $signers = $app->make(DigestSignerFactory::class);

            return new Client(
                $app->make(Config::class),
                $transport,
                $app->make(ClockInterface::class),
                static fn ($credentials): DigestSigner => $signers($credentials),
            );
        });
        $this->app->alias(Client::class, 'selcom');

        $this->registerWebhookServices();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/selcom.php' => $this->app->configPath('selcom.php')], 'selcom-config');

            $stamp = date('Y_m_d_His');
            $this->publishes([
                __DIR__ . '/../database/migrations/create_selcom_orders_table.php.stub'
                    => $this->app->databasePath('migrations/' . $stamp . '_create_selcom_orders_table.php'),
                __DIR__ . '/../database/migrations/create_selcom_payouts_table.php.stub'
                    => $this->app->databasePath('migrations/' . $stamp . '_create_selcom_payouts_table.php'),
                __DIR__ . '/../database/migrations/create_selcom_webhook_deliveries_table.php.stub'
                    => $this->app->databasePath('migrations/' . $stamp . '_create_selcom_webhook_deliveries_table.php'),
            ], 'selcom-migrations');

            $this->commands([StatusCommand::class, ReconcileCommand::class]);
        }

        $this->registerWebhookRoute();
    }

    private function registerWebhookServices(): void
    {
        $this->app->singleton(ReplayStore::class, static function (Application $app): ReplayStore {
            $config = $app->make(ConfigRepository::class);
            $store = $config->get('selcom.webhook.cache_store');
            $prefix = $config->get('selcom.webhook.cache_prefix', 'selcom:webhook:');

            return new CacheReplayStore(
                $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null),
                is_string($prefix) ? $prefix : 'selcom:webhook:',
                $app->make(ClockInterface::class),
            );
        });

        $this->app->singleton(WebhookVerifier::class, static function (Application $app): WebhookVerifier {
            $config = $app->make(ConfigRepository::class);
            $secret = $config->get('selcom.webhook.secret');
            $secret = is_string($secret) && trim($secret) !== ''
                ? trim($secret)
                : $app->make(Config::class)->credentialsFor(\SelcomDevelopers\Selcom\Operation::CheckoutOrderStatus)->secret();
            $window = $config->get('selcom.webhook.window_seconds', 300);

            return new WebhookVerifier(
                $secret,
                $app->make(ClockInterface::class),
                $app->make(ReplayStore::class),
                is_int($window) ? $window : 300,
            );
        });

        $this->app->singleton(WebhookStore::class, static function (Application $app): WebhookStore {
            $config = $app->make(ConfigRepository::class);
            $mode = $config->get('selcom.webhook.store', 'none');
            if ($mode === 'database') {
                $model = $config->get('selcom.models.webhook_delivery');

                return new DatabaseWebhookStore(
                    is_string($model) && is_a($model, SelcomWebhookDelivery::class, true) ? $model : SelcomWebhookDelivery::class,
                );
            }
            if ($mode === 'none' || $mode === null || $mode === '') {
                return new NullWebhookStore();
            }

            throw new ConfigurationException('"selcom.webhook.store" must be "database" or "none".');
        });
    }

    private function registerWebhookRoute(): void
    {
        $config = $this->app->make(ConfigRepository::class);
        if ($config->get('selcom.webhook.enabled') !== true || $this->app->routesAreCached()) {
            return;
        }

        $path = $config->get('selcom.webhook.path');
        $middleware = $config->get('selcom.webhook.middleware', []);
        $name = $config->get('selcom.webhook.route_name', 'selcom.webhook');
        if (!is_string($path) || trim($path, '/') === '' || trim($path, '/') === 'selcom/webhook') {
            throw new ConfigurationException(
                'The webhook is enabled but "selcom.webhook.path" (SELCOM_WEBHOOK_PATH) is empty or the bare default. '
                . 'Set a path with an unguessable token, for example "selcom/webhook/<random>". The route was not registered.',
            );
        }

        $limit = $config->get('selcom.webhook.rate_limit', 120);
        RateLimiter::for('selcom-webhook', static fn (Request $request): Limit => Limit::perMinute(is_int($limit) && $limit > 0 ? $limit : 120)->by((string) $request->ip()));

        // The gateway sends no CSRF token and no session, so the route is registered outside the
        // "web" group; whatever you list in `selcom.webhook.middleware` is all that runs.
        Route::post(trim($path, '/'), WebhookController::class)
            ->middleware(is_array($middleware) ? $middleware : [])
            ->name(is_string($name) ? $name : 'selcom.webhook');
    }
}
