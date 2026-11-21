<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Support\ConfigFactory;
use SelcomDevelopers\LaravelSelcom\Support\DigestSignerFactory;
use SelcomDevelopers\LaravelSelcom\Tests\Support\FixedClock;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Requests;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\LaravelSelcom\Webhook\CacheReplayStore;
use SelcomDevelopers\LaravelSelcom\Webhook\NullWebhookStore;
use SelcomDevelopers\LaravelSelcom\Webhook\WebhookStore;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Config;
use SelcomDevelopers\Selcom\Environment;
use SelcomDevelopers\Selcom\Exception\ConfigurationException;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Redaction\Redactor;
use SelcomDevelopers\Selcom\Signing\DigestSigner;
use SelcomDevelopers\Selcom\Transport\Transport;
use SelcomDevelopers\Selcom\Webhook\ReplayStore;
use SelcomDevelopers\Selcom\Webhook\WebhookVerifier;

final class ProviderTest extends TestCase
{
    public function testEveryLayerIsBoundInTheContainer(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        foreach ([Config::class, Transport::class, DigestSigner::class, DigestSignerFactory::class, Redactor::class,
            ReplayStore::class, WebhookVerifier::class, WebhookStore::class, Client::class] as $abstract) {
            self::assertInstanceOf($abstract, $app->make($abstract));
        }
        self::assertSame($app->make(Client::class), $app->make('selcom'));
        self::assertInstanceOf(CacheReplayStore::class, $app->make(ReplayStore::class));
        self::assertInstanceOf(NullWebhookStore::class, $app->make(WebhookStore::class));
        self::assertSame(Environment::Sandbox, $app->make(Config::class)->environment);
    }

    public function testEveryLayerCanBeReplaced(): void
    {
        $app = $this->app;
        self::assertNotNull($app);

        $custom = new class implements Transport {
            public int $calls = 0;

            public function send(\SelcomDevelopers\Selcom\Transport\TransportRequest $request): \SelcomDevelopers\Selcom\Transport\TransportResponse
            {
                ++$this->calls;

                return new \SelcomDevelopers\Selcom\Transport\TransportResponse(200, '{"resultcode":"000","result":"SUCCESS","data":[]}');
            }
        };
        $app->instance(Transport::class, $custom);

        $result = Selcom::execute(Requests::for(Operation::CheckoutOrderStatus));
        self::assertSame(1, $custom->calls);
        self::assertSame(200, $result->httpStatus);
    }

    public function testThereIsNoDefaultEnvironment(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('There is no default');

        $settings = require __DIR__ . '/../../config/selcom.php';
        self::assertIsArray($settings);
        self::assertNull($settings['environment']);
        /** @var array<string, mixed> $settings */
        ConfigFactory::make($settings + ['key' => 'k', 'secret' => 's', 'vendor' => 'v']);
    }

    public function testMissingCredentialsFailWhenTheConfigIsResolved(): void
    {
        $this->app?->make('config')->set('selcom.secret', '');

        $this->expectException(ConfigurationException::class);
        $this->app?->make(Config::class);
    }

    public function testCheckoutCredentialsAndTimeoutsComeFromConfig(): void
    {
        $config = ConfigFactory::make([
            'environment' => 'production',
            'key' => 'test-key-0001', 'secret' => 'test-secret-0001', 'vendor' => 'TILL123',
            'checkout' => ['key' => 'checkout-key-9', 'secret' => 'checkout-secret-9', 'vendor' => null],
            'timeouts' => ['payment_read' => '120', 'query_read' => 7.5],
            'base_url' => '',
        ]);

        self::assertSame('checkout-key-9', $config->credentialsFor(Operation::CheckoutOrderStatus)->apiKey());
        self::assertSame('test-key-0001', $config->credentialsFor(Operation::VendorBalance)->apiKey());
        self::assertSame(120.0, $config->timeouts->payment->readSeconds);
        self::assertSame(7.5, $config->timeouts->query->readSeconds);
        self::assertSame('https://apigw.selcommobile.com', $config->baseUrl());
    }

    public function testHalfACheckoutPairIsAMistake(): void
    {
        $this->expectException(ConfigurationException::class);
        ConfigFactory::make([
            'environment' => 'sandbox', 'key' => 'k', 'secret' => 's', 'vendor' => 'v',
            'checkout' => ['key' => 'only-a-key', 'secret' => null],
        ]);
    }

    public function testPrintingNeverShowsTheSecretPinOrFullKey(): void
    {
        $client = $this->app?->make(Client::class);
        $config = $this->app?->make(Config::class);
        $printed = print_r($client, true) . print_r($config, true);
        ob_start();
        var_dump($client, $config);
        $printed .= (string) ob_get_clean();

        self::assertStringNotContainsString('test-secret-0001', $printed);
        self::assertStringNotContainsString('test-key-0001', $printed);
        self::assertStringNotContainsString("'0000'", $printed);
        self::assertStringNotContainsString('"0000"', $printed);
    }

    public function testCredentialsCannotBeSerialised(): void
    {
        $this->expectException(\LogicException::class);
        serialize($this->app?->make(Config::class)->credentials);
    }

    public function testWebhookRouteExistsOnlyWhenEnabled(): void
    {
        self::assertFalse(Route::has('selcom.webhook'));
    }

    public function testCommandsAreRegistered(): void
    {
        $all = Artisan::all();
        self::assertArrayHasKey('selcom:status', $all);
        self::assertArrayHasKey('selcom:reconcile', $all);
    }

    public function testMigrationsAreNotLoadedUnlessPublished(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        \SelcomDevelopers\LaravelSelcom\Models\SelcomOrder::query()->count();
    }

    public function testReplayStoreKeepsADigestThroughItsKeepUntilSecond(): void
    {
        $now = 1_800_000_000;
        $clock = FixedClock::at('@' . $now);
        $store = new CacheReplayStore(new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()), 'p:', $clock);

        self::assertFalse($store->seen('digest-a', $now + 300), 'first sighting');
        $this->travel(299)->seconds();
        self::assertTrue($store->seen('digest-a', $now + 300), 'still live inside the window');
        self::assertFalse($store->seen('digest-b', $now + 300), 'another digest is its own sighting');
    }

    public function testReplayStoreTurnsACacheFailureIntoAReplayStoreException(): void
    {
        $failing = new class extends \Illuminate\Cache\Repository {
            public function __construct()
            {
                parent::__construct(new \Illuminate\Cache\ArrayStore());
            }

            /**
             * @param string $key
             */
            public function add($key, $value, $ttl = null): bool
            {
                throw new \RuntimeException('cache down');
            }
        };

        $this->expectException(\SelcomDevelopers\Selcom\Exception\ReplayStoreException::class);
        (new CacheReplayStore($failing))->seen('digest-a', time() + 300);
    }
}
