<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Mockery;
use Psr\Clock\ClockInterface;
use SelcomDevelopers\LaravelSelcom\Events\OrderSucceeded;
use SelcomDevelopers\LaravelSelcom\Exception\ResultMismatchException;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Jobs\ConfirmOrderStatus;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Services\FinalStateGuard;
use SelcomDevelopers\LaravelSelcom\Testing\Scenarios;
use SelcomDevelopers\LaravelSelcom\Tests\Support\FixedClock;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Vectors;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Operation;

final class FollowUpFixesTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app['config'];
        $config->set('selcom.webhook.enabled', true);
        $config->set('selcom.webhook.path', 'selcom/webhook/test-token-0123456789');
    }

    // ---- the throttle limiter exists when routes are cached ---------------------------------

    public function testACachedRouteStillAnswersADelivery(): void
    {
        $this->runMigrationStubs();

        // Write the cached-routes file the way route:cache does: the compiled route collection.
        $router = $this->app?->make('router');
        \assert($router instanceof \Illuminate\Routing\Router);
        $routes = $router->getRoutes();
        \assert($routes instanceof \Illuminate\Routing\RouteCollection);
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
        $compiled = $routes->compile();
        $current = $this->app;
        self::assertNotNull($current);
        $path = $current->getCachedRoutesPath();
        file_put_contents(
            $path,
            '<?php app("router")->setCompiledRoutes(' . var_export($compiled, true) . ');' . PHP_EOL,
        );

        try {
            // A new application boots from the cached routes: the provider never reaches its own route
            // registration, but the cached route still names the throttle limiter.
            $this->refreshApplication();
            $app = $this->app;
            self::assertNotNull($app);
            self::assertTrue($app->routesAreCached());
            // What the framework's route service provider does when routes are cached.
            require $app->getCachedRoutesPath();
            $app->make('router')->getRoutes()->refreshNameLookups();
            $now = Vectors::load('responses/checkout.webhook.json')['verification'];
            \assert(is_array($now) && is_string($now['now']));
            $app->instance(ClockInterface::class, FixedClock::at($now['now']));

            $this->call('POST', '/selcom/webhook/test-token-0123456789', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"order_id":"ORD-1","transid":"TXN-1"}')
                ->assertOk();
        } finally {
            @unlink($path);
        }
    }

    // ---- the confirmation job ----------------------------------------------------------------

    public function testTheConfirmationJobBacksOffAfterAnException(): void
    {
        $job = new ConfirmOrderStatus('ORD-1001');

        self::assertSame(180, $job->backoff());
        $this->app?->make('config')->set('selcom.webhook.confirm_interval_seconds', 60);
        self::assertSame(60, $job->backoff());
    }

    public function testTheConfirmationJobIsUniquePerOrder(): void
    {
        self::assertSame((new ConfirmOrderStatus('ORD-1'))->uniqueId(), (new ConfirmOrderStatus('ORD-1', 'TXN-A'))->uniqueId());
        self::assertNotSame((new ConfirmOrderStatus('ORD-1'))->uniqueId(), (new ConfirmOrderStatus('ORD-2'))->uniqueId());
        self::assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, new ConfirmOrderStatus('ORD-1'));
        self::assertSame(1800, (new ConfirmOrderStatus('ORD-1'))->uniqueFor());
    }

    public function testTheConfirmationJobFailsForAnErrorThatRetryingCannotFix(): void
    {
        $this->runMigrationStubs();
        SelcomOrder::query()->create(['order_id' => 'ORD-1001']);
        Selcom::fake(['checkout.order-status' => 'completed']);
        $throwing = new class ($this->app?->make(Dispatcher::class) ?? throw new \LogicException()) extends FinalStateGuard {
            public function applyOrderResult(SelcomOrder $order, \SelcomDevelopers\Selcom\Result\Result $result): string
            {
                throw new ResultMismatchException('wrong record');
            }
        };

        /** @var \Illuminate\Contracts\Queue\Job&\Mockery\MockInterface $queued */
        $queued = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queued->shouldReceive('fail')->once();
        $queued->shouldNotReceive('release');
        $job = new ConfirmOrderStatus('ORD-1001');
        $job->setJob($queued);

        $client = app(Client::class);
        $events = app(Dispatcher::class);
        $job->handle($client, $events, $throwing);
        $this->addToAssertionCount(1);
    }

    public function testTheConfirmationJobRetriesWhenThereWasNoAnswer(): void
    {
        Selcom::fake(['checkout.order-status' => Scenarios::unknownOutcome()]);

        /** @var \Illuminate\Contracts\Queue\Job&\Mockery\MockInterface $queued */
        $queued = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queued->shouldNotReceive('fail');
        $job = new ConfirmOrderStatus('ORD-1001');
        $job->setJob($queued);

        $this->expectException(\SelcomDevelopers\Selcom\Exception\TransportException::class);
        $this->app?->call([$job, 'handle']);
    }

    // ---- bounded polling of pending orders and a listener that throws -----------------------

    public function testPendingOrdersAreHandedToAPersonAfterTheirMaximumAge(): void
    {
        $this->runMigrationStubs();
        $this->app?->make('config')->set('selcom.reconcile.pending_max_age_minutes', 120);
        $old = SelcomOrder::query()->create(['order_id' => 'ORD-OLD']);
        $young = SelcomOrder::query()->create(['order_id' => 'ORD-YOUNG']);
        foreach ([$old->getKey() => 600, $young->getKey() => 30] as $id => $minutes) {
            SelcomOrder::query()->whereKey($id)->toBase()->update([
                'created_at' => now()->subMinutes($minutes),
                'updated_at' => now()->subMinutes(30),
            ]);
        }
        $fake = Selcom::fake(['checkout.order-status' => 'pending']);

        self::assertSame(0, Artisan::call('selcom:reconcile', ['--type' => 'orders']));

        self::assertTrue($old->refresh()->needs_review);
        self::assertStringContainsString('120', (string) $old->review_reason);
        self::assertFalse($young->refresh()->needs_review);
        $fake->assertSentCount(1);
        $fake->assertSent(Operation::CheckoutOrderStatus, static fn ($r): bool => $r->param('order_id') === 'ORD-YOUNG');
    }

    public function testAListenerThatThrowsDoesNotStopTheReconcileRun(): void
    {
        $this->runMigrationStubs();
        foreach (['ORD-A', 'ORD-B'] as $id) {
            $order = SelcomOrder::query()->create(['order_id' => $id]);
            SelcomOrder::query()->whereKey($order->getKey())->toBase()->update(['updated_at' => now()->subMinutes(30)]);
        }
        Event::listen(OrderSucceeded::class, static function (): void {
            throw new \RuntimeException('fulfilment failed');
        });
        $fake = Selcom::fake(['checkout.order-status' => 'completed']);

        $exit = Artisan::call('selcom:reconcile', ['--type' => 'orders']);

        self::assertSame(1, $exit, 'the failures are reported in the exit code');
        $fake->assertSentCount(2);
        self::assertSame(['success', 'success'], SelcomOrder::query()->orderBy('order_id')->pluck('state')->all());
    }
}
