<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Psr\Clock\ClockInterface;
use SelcomDevelopers\LaravelSelcom\Events\OrderStatusConfirmed;
use SelcomDevelopers\LaravelSelcom\Events\WebhookReceived;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Jobs\ConfirmOrderStatus;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Models\SelcomWebhookDelivery;
use SelcomDevelopers\LaravelSelcom\Tests\Support\FixedClock;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Vectors;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\LaravelSelcom\Webhook\StoreOutcome;
use SelcomDevelopers\LaravelSelcom\Webhook\WebhookStore;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;

final class WebhookControllerTest extends TestCase
{
    private const URL = '/selcom/webhook/test-token';

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app['config'];
        $config->set('selcom.webhook.enabled', true);
        $config->set('selcom.webhook.path', 'selcom/webhook/test-token');
        $config->set('selcom.webhook.store', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationStubs();

        $now = Vectors::load('responses/checkout.webhook.json')['verification'];
        \assert(is_array($now) && is_string($now['now']));
        $this->app?->instance(ClockInterface::class, FixedClock::at($now['now']));
    }

    /**
     * @return array{array<string, string>, string}
     */
    private function sample(string $name): array
    {
        $samples = Vectors::load('responses/checkout.webhook.json')['samples'];
        \assert(is_array($samples));
        foreach ($samples as $sample) {
            \assert(is_array($sample) && is_array($sample['headers']) && is_string($sample['raw_body']));
            if ($sample['name'] === $name) {
                $server = [];
                foreach ($sample['headers'] as $header => $value) {
                    $key = strtoupper(str_replace('-', '_', (string) $header));
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
    private function deliver(string $name): \Illuminate\Testing\TestResponse
    {
        [$server, $raw] = $this->sample($name);

        return $this->call('POST', self::URL, [], [], [], $server, $raw);
    }

    public function testAValidDeliveryIsStoredRedactedAndAcknowledged(): void
    {
        $this->deliver('completed-signed')->assertOk();

        $row = SelcomWebhookDelivery::query()->sole();
        self::assertSame('ORD-2001|TXN-WLT-2001', $row->dedupe_key);
        self::assertSame('VALID', $row->verdict);
        self::assertStringNotContainsString('255700000001', $row->body, 'the phone number is masked');
        self::assertStringContainsString('********0001', $row->body);
        self::assertStringNotContainsString('u/h/Ix4sMp69scjQKUqYET', json_encode($row->headers, JSON_THROW_ON_ERROR), 'Digest is never stored');
        self::assertStringNotContainsString('SELCOM Mj', json_encode($row->headers, JSON_THROW_ON_ERROR), 'Authorization is never stored');
    }

    public function testARedeliveryIsAcknowledgedAndNotStoredTwice(): void
    {
        Queue::fake();
        $this->app?->make('config')->set('selcom.webhook.confirm', true);

        $this->deliver('completed-signed')->assertOk();
        $this->deliver('completed-signed-redelivered')->assertOk(); // REPLAY by digest

        self::assertSame(1, SelcomWebhookDelivery::query()->count());
        Queue::assertPushed(ConfirmOrderStatus::class, 1);
    }

    public function testTheDurableGuardCatchesADuplicateThatHasANewDigest(): void
    {
        Queue::fake();
        $this->app?->make('config')->set('selcom.webhook.confirm', true);

        $this->deliver('completed-signed')->assertOk();
        // Same order and transid, different signed delivery (an unsigned redelivery, say).
        $store = $this->app?->make(WebhookStore::class);
        self::assertNotNull($store);
        $payload = WebhookPayload::fromRawBody('{"order_id":"ORD-2001","transid":"TXN-WLT-2001","amount":"x"}');
        self::assertSame(StoreOutcome::Duplicate, $store->store($payload, WebhookVerdict::Unsigned, [], '{}'));
        self::assertSame(1, SelcomWebhookDelivery::query()->count());
    }

    public function testInvalidAndStaleAreRejectedWithNothingStored(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->deliver('signed-field-tampered')->assertStatus(401);
        $this->deliver('stale-timestamp')->assertStatus(401);

        self::assertSame(0, SelcomWebhookDelivery::query()->count());
    }

    public function testReportOnlyModeProcessesAnInvalidDeliveryAndAnswers200(): void
    {
        $this->app?->make('config')->set('selcom.webhook.mode', 'report_only');

        $this->deliver('wrong-secret')->assertOk();

        self::assertSame('INVALID', SelcomWebhookDelivery::query()->sole()->verdict);
    }

    public function testAnUnsignedDeliveryWithAnUnparseableAmountIsStillAcknowledged(): void
    {
        $raw = '{"order_id":"ORD-3001","transid":"TXN-3001","payment_status":"COMPLETED","amount":"n/a"}';
        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertOk();

        $row = SelcomWebhookDelivery::query()->sole();
        self::assertSame('UNSIGNED', $row->verdict);
    }

    public function testAnUnsignedBodyThatIsNotAJsonObjectIsRefusedWithoutStoring(): void
    {
        $this->call('POST', self::URL, [], [], [], [], 'not json')->assertStatus(400);
        self::assertSame(0, SelcomWebhookDelivery::query()->count());
    }

    public function testAFailedStoreAnswers500SoTheGatewayRetries(): void
    {
        $this->app?->instance(WebhookStore::class, new class implements WebhookStore {
            public function store(WebhookPayload $payload, WebhookVerdict $verdict, array $redactedHeaders, string $redactedBody): StoreOutcome
            {
                throw new \RuntimeException('disk full');
            }
        });
        $this->withoutExceptionHandling([\RuntimeException::class]);

        $this->deliver('completed-signed')->assertStatus(500);
    }

    public function testAFailingReplayStoreAnswers500SoTheGatewayRetries(): void
    {
        $this->app?->instance(\SelcomDevelopers\Selcom\Webhook\ReplayStore::class, new class implements \SelcomDevelopers\Selcom\Webhook\ReplayStore {
            public function seen(string $digest, int $keepUntil): bool
            {
                throw new \SelcomDevelopers\Selcom\Exception\ReplayStoreException('down');
            }
        });
        $this->app?->forgetInstance(\SelcomDevelopers\Selcom\Webhook\WebhookVerifier::class);
        $this->withoutExceptionHandling([\SelcomDevelopers\Selcom\Exception\ReplayStoreException::class]);

        $this->deliver('completed-signed')->assertStatus(500);
        self::assertSame(0, SelcomWebhookDelivery::query()->count());
    }

    public function testNothingIsQueuedUnlessConfirmationIsOn(): void
    {
        Queue::fake();
        $this->deliver('completed-signed')->assertOk();
        Queue::assertNothingPushed();
    }

    public function testAQueueFailureNeverFailsTheAcknowledgement(): void
    {
        $this->app?->make('config')->set('selcom.webhook.confirm', true);
        $this->app?->make('config')->set('selcom.webhook.queue.connection', 'no-such-connection');
        $this->withoutExceptionHandling([\InvalidArgumentException::class]);

        $this->deliver('completed-signed')->assertOk();
        self::assertSame(1, SelcomWebhookDelivery::query()->count());
    }

    public function testTheConfirmationJobQueriesOrderStatusAndAppliesItThroughTheGuard(): void
    {
        SelcomOrder::query()->create(['order_id' => 'ORD-2001', 'amount' => 5000, 'currency' => 'TZS']);
        $fake = Selcom::fake(['checkout.order-status' => 'completed']);
        Event::fake([OrderStatusConfirmed::class]);

        dispatch_sync(new ConfirmOrderStatus('ORD-2001', 'TXN-WLT-2001'));

        $fake->assertSentCount(1);
        $fake->assertSent(Operation::CheckoutOrderStatus, static fn ($r): bool => $r->param('order_id') === 'ORD-2001');
        Event::assertDispatched(OrderStatusConfirmed::class);
        self::assertSame('success', SelcomOrder::query()->sole()->state);
    }
}
