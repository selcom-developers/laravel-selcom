<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Psr\Clock\ClockInterface;
use SelcomDevelopers\LaravelSelcom\Events\WebhookReceived;
use SelcomDevelopers\LaravelSelcom\Events\WebhookValid;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Jobs\ConfirmOrderStatus;
use SelcomDevelopers\LaravelSelcom\Models\SelcomWebhookDelivery;
use SelcomDevelopers\LaravelSelcom\SelcomServiceProvider;
use SelcomDevelopers\LaravelSelcom\Tests\Support\FixedClock;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Vectors;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\LaravelSelcom\Webhook\StoreOutcome;
use SelcomDevelopers\LaravelSelcom\Webhook\WebhookStore;
use SelcomDevelopers\Selcom\Exception\ConfigurationException;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;

final class WebhookHardeningTest extends TestCase
{
    private const URL = '/selcom/webhook/test-token-0123456789';

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app['config'];
        $config->set('selcom.webhook.enabled', true);
        $config->set('selcom.webhook.path', 'selcom/webhook/test-token-0123456789');
        $config->set('selcom.webhook.store', 'database');
        $config->set('selcom.webhook.confirm', true);
        $config->set('selcom.webhook.rate_limit', 5);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationStubs();

        $verification = Vectors::load('responses/checkout.webhook.json')['verification'];
        \assert(is_array($verification) && is_string($verification['now']));
        $this->app?->instance(ClockInterface::class, FixedClock::at($verification['now']));
    }

    /**
     * @return array{array<string, string>, string}
     */
    private function sample(string $name, bool $withoutSignature = false): array
    {
        $samples = Vectors::load('responses/checkout.webhook.json')['samples'];
        \assert(is_array($samples));
        foreach ($samples as $sample) {
            \assert(is_array($sample) && is_array($sample['headers']) && is_string($sample['raw_body']));
            if ($sample['name'] === $name) {
                $server = [];
                foreach ($sample['headers'] as $header => $value) {
                    $key = strtoupper(str_replace('-', '_', (string) $header));
                    if ($withoutSignature && $key !== 'CONTENT_TYPE') {
                        continue;
                    }
                    $server[$key === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_' . $key] = (string) $value;
                }

                return [$server, $sample['raw_body']];
            }
        }

        throw new \LogicException('No sample ' . $name);
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function deliver(string $name, bool $withoutSignature = false): \Illuminate\Testing\TestResponse
    {
        [$server, $raw] = $this->sample($name, $withoutSignature);

        return $this->call('POST', self::URL, [], [], [], $server, $raw);
    }

    // ---- 1. the redelivery after a 5xx is processed ----------------------------------------

    public function testTheRedeliveryAfterAFailedStoreIsProcessedNotTreatedAsReplay(): void
    {
        Queue::fake();
        $real = $this->app?->make(WebhookStore::class);
        \assert($real instanceof WebhookStore);
        $this->app?->instance(WebhookStore::class, new class ($real) implements WebhookStore {
            private int $calls = 0;

            public function __construct(private readonly WebhookStore $inner)
            {
            }

            public function store(WebhookPayload $payload, WebhookVerdict $verdict, array $redactedHeaders, string $redactedBody): StoreOutcome
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('database unavailable');
                }

                return $this->inner->store($payload, $verdict, $redactedHeaders, $redactedBody);
            }
        });
        $this->withoutExceptionHandling([\RuntimeException::class]);

        $this->deliver('completed-signed')->assertStatus(500);
        self::assertSame(0, SelcomWebhookDelivery::query()->count());

        // The gateway sends the identical delivery again: it must be processed.
        $this->deliver('completed-signed')->assertOk();
        self::assertSame(1, SelcomWebhookDelivery::query()->count());
        self::assertSame('VALID', SelcomWebhookDelivery::query()->sole()->verdict);
        Queue::assertPushed(ConfirmOrderStatus::class, 1);
    }

    public function testAThrowingListenerNeverTurnsAStoredDeliveryInto5xx(): void
    {
        Queue::fake();
        Event::listen(WebhookReceived::class, static function (): void {
            throw new \RuntimeException('listener bug');
        });
        Event::listen(WebhookValid::class, static function (): void {
            throw new \RuntimeException('listener bug');
        });
        $this->withoutExceptionHandling([\RuntimeException::class]);

        $this->deliver('completed-signed')->assertOk();

        self::assertSame(1, SelcomWebhookDelivery::query()->count());
        Queue::assertPushed(ConfirmOrderStatus::class, 1);
    }

    public function testEventsFireAfterTheDeliveryIsStored(): void
    {
        Queue::fake();
        $storedWhenFired = null;
        Event::listen(WebhookValid::class, static function () use (&$storedWhenFired): void {
            $storedWhenFired = SelcomWebhookDelivery::query()->count();
        });

        $this->deliver('completed-signed')->assertOk();

        self::assertSame(1, $storedWhenFired);
    }

    // ---- 2. a stronger verdict upgrades the stored row and re-queues confirmation ----------

    public function testAValidDeliveryUpgradesAnUnsignedRowAndQueuesConfirmationAgain(): void
    {
        Queue::fake();

        $this->deliver('completed-signed', withoutSignature: true)->assertOk();
        self::assertSame('UNSIGNED', SelcomWebhookDelivery::query()->sole()->verdict);

        $this->deliver('completed-signed')->assertOk();

        self::assertSame(1, SelcomWebhookDelivery::query()->count());
        self::assertSame('VALID', SelcomWebhookDelivery::query()->sole()->verdict);
        Queue::assertPushed(ConfirmOrderStatus::class, 2);
    }

    public function testAnUnsignedDeliveryAfterAValidOneChangesNothing(): void
    {
        Queue::fake();

        $this->deliver('completed-signed')->assertOk();
        $this->deliver('completed-signed', withoutSignature: true)->assertOk();

        self::assertSame('VALID', SelcomWebhookDelivery::query()->sole()->verdict);
        Queue::assertPushed(ConfirmOrderStatus::class, 1);
    }

    public function testTheConfirmationJobAsksAgainWhileTheOrderIsPending(): void
    {
        Selcom::fake(['checkout.order-status' => 'pending']);
        $job = new ConfirmOrderStatus('ORD-1001');
        /** @var \Illuminate\Contracts\Queue\Job&\Mockery\MockInterface $queued */
        $queued = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queued->shouldReceive('release')->once()->with(180);
        $job->setJob($queued);

        $this->app?->call([$job, 'handle']);
    }

    public function testTheConfirmationJobStopsOnceTheOrderIsSettled(): void
    {
        Selcom::fake(['checkout.order-status' => 'completed']);
        $job = new ConfirmOrderStatus('ORD-1001');
        /** @var \Illuminate\Contracts\Queue\Job&\Mockery\MockInterface $queued */
        $queued = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queued->shouldNotReceive('release');
        $job->setJob($queued);

        $this->app?->call([$job, 'handle']);
        $this->addToAssertionCount(1);
    }

    public function testTheConfirmationWindowIsBounded(): void
    {
        $this->app?->make('config')->set('selcom.webhook.confirm_window_minutes', 10);
        $job = new ConfirmOrderStatus('ORD-1001');

        $until = $job->retryUntil();
        self::assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), $until->getTimestamp(), 5);
    }

    // ---- 9. route hardening -----------------------------------------------------------------

    public function testTheDefaultThrottleLimitsDeliveriesByClient(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"order_id":"ORD-9' . $i . '","transid":"TXN-9' . $i . '"}')->assertOk();
        }

        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"order_id":"ORD-99","transid":"TXN-99"}')->assertStatus(429);
    }

    public function testTheBareDefaultPathIsNotRegistered(): void
    {
        $config = $this->app?->make(Repository::class);
        \assert($config instanceof Repository);
        $config->set('selcom.webhook.path', 'selcom/webhook');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('unguessable token');
        (new SelcomServiceProvider($this->app ?? throw new \LogicException()))->boot();
    }

    public function testAMissingPathIsNotRegisteredEither(): void
    {
        $config = $this->app?->make(Repository::class);
        \assert($config instanceof Repository);
        $config->set('selcom.webhook.path', null);

        $this->expectException(ConfigurationException::class);
        (new SelcomServiceProvider($this->app ?? throw new \LogicException()))->boot();
    }

    public function testOversizedBodiesAreRefusedBeforeAnythingParsesThem(): void
    {
        $this->app?->make('config')->set('selcom.webhook.max_body_bytes', 1024);
        Event::fake([WebhookReceived::class]);

        $body = '{"order_id":"ORD-1","transid":"TXN-1","note":"' . str_repeat('x', 2000) . '"}';
        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(413);

        Event::assertNotDispatched(WebhookReceived::class);
        self::assertSame(0, SelcomWebhookDelivery::query()->count());
    }

    public function testUnsignedDeliveriesCanBeRefused(): void
    {
        $this->app?->make('config')->set('selcom.webhook.refuse_unsigned', true);

        $this->deliver('completed-signed', withoutSignature: true)->assertStatus(401);
        self::assertSame(0, SelcomWebhookDelivery::query()->count());

        $this->deliver('completed-signed')->assertOk();
        self::assertSame(1, SelcomWebhookDelivery::query()->count());
    }

    public function testUnsignedDeliveriesAreAcceptedByDefault(): void
    {
        Queue::fake();
        $this->deliver('completed-signed', withoutSignature: true)->assertOk();
        self::assertSame(1, SelcomWebhookDelivery::query()->count());
    }
}
