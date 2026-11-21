<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use Illuminate\Testing\PendingCommand;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Models\SelcomPayout;
use SelcomDevelopers\LaravelSelcom\Testing\SentRequest;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\Selcom\Operation;

final class ReconcileBoundsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationStubs();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function cmd(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        \assert($pending instanceof PendingCommand);

        return $pending;
    }

    private function age(SelcomOrder|SelcomPayout $record, int $updatedMinutesAgo, ?int $createdMinutesAgo = null): void
    {
        $record->newQuery()->whereKey($record->getKey())->toBase()->update([
            'updated_at' => now()->subMinutes($updatedMinutesAgo),
            'created_at' => now()->subMinutes($createdMinutesAgo ?? $updatedMinutesAgo),
        ]);
    }

    private function pendingBody(): \Closure
    {
        return static fn (SentRequest $r): array => ['resultcode' => '000', 'data' => [['payment_status' => 'PENDING']]];
    }

    public function testNotFoundOrdersAreStillPolledForABoundedWindow(): void
    {
        $order = SelcomOrder::query()->create(['order_id' => 'ORD-1001', 'state' => 'not_found']);
        $this->age($order, 10, 20);
        $fake = Selcom::fake(['checkout.order-status' => 'completed']);

        $this->cmd('selcom:reconcile')->assertExitCode(0);

        $fake->assertSent(Operation::CheckoutOrderStatus);
        self::assertSame('success', $order->refresh()->state, 'an order that appeared at the gateway is picked up');
    }

    public function testAbandonedNotFoundOrdersAreHandedToAPersonAndNotPolledAgain(): void
    {
        $this->app?->make('config')->set('selcom.reconcile.not_found_max_age_minutes', 30);
        $abandoned = SelcomOrder::query()->create(['order_id' => 'ORD-1001', 'state' => 'not_found']);
        $this->age($abandoned, 40, 120);
        $fake = Selcom::fake(['checkout.order-status' => $this->pendingBody()]);

        $this->cmd('selcom:reconcile')->assertExitCode(0);

        $abandoned->refresh();
        self::assertTrue($abandoned->needs_review);
        self::assertSame('not_found', $abandoned->state);
        $fake->assertNothingSent();

        $this->cmd('selcom:reconcile')->assertExitCode(0);
        $fake->assertNothingSent();
    }

    public function testAbandonedRowsCannotCrowdOutRealOnesBeyondTheLimit(): void
    {
        $this->app?->make('config')->set('selcom.reconcile.not_found_max_age_minutes', 30);
        foreach (['ORD-OLD-1', 'ORD-OLD-2'] as $id) {
            $this->age(SelcomOrder::query()->create(['order_id' => $id, 'state' => 'not_found']), 90, 300);
        }
        $real = SelcomOrder::query()->create(['order_id' => 'ORD-REAL', 'state' => 'pending']);
        $this->age($real, 10);
        $fake = Selcom::fake(['checkout.order-status' => 'completed']);

        $this->cmd('selcom:reconcile', ['--type' => 'orders', '--limit' => 1])->assertExitCode(0);

        $fake->assertSentCount(1);
        $fake->assertSent(Operation::CheckoutOrderStatus, static fn (SentRequest $r): bool => $r->param('order_id') === 'ORD-REAL');
    }

    public function testRecordsFlaggedForReviewAreNotPolled(): void
    {
        $order = SelcomOrder::query()->create(['order_id' => 'ORD-1001', 'state' => 'pending', 'needs_review' => true]);
        $this->age($order, 30);
        $fake = Selcom::fake();

        $this->cmd('selcom:reconcile')->assertExitCode(0);

        $fake->assertNothingSent();
    }

    public function testASubmittingPayoutIsLeftAloneUntilThePaymentTimeoutHasPassed(): void
    {
        // payment timeouts 15 + 180 seconds, plus a 30 second margin: 225 seconds.
        $young = SelcomPayout::query()->create(['transid' => 'TXN-UTL-0001', 'kind' => 'utility', 'state' => 'submitting']);
        $young->newQuery()->whereKey($young->getKey())->toBase()->update(['updated_at' => now()->subSeconds(200)]);
        $fake = Selcom::fake(['utilitypayment.query' => 'pending']);

        $this->cmd('selcom:reconcile', ['--older-than' => 0, '--type' => 'payouts'])->assertExitCode(0);
        $fake->assertNothingSent();

        $young->newQuery()->whereKey($young->getKey())->toBase()->update(['updated_at' => now()->subSeconds(300)]);
        $this->cmd('selcom:reconcile', ['--older-than' => 0, '--type' => 'payouts'])->assertExitCode(0);
        $fake->assertSent(Operation::UtilityPaymentQuery);
    }

    public function testAPendingPayoutDoesNotWaitForThePaymentTimeout(): void
    {
        $payout = SelcomPayout::query()->create(['transid' => 'TXN-UTL-0001', 'kind' => 'utility', 'state' => 'pending']);
        $payout->newQuery()->whereKey($payout->getKey())->toBase()->update(['updated_at' => now()->subSeconds(100)]);
        $fake = Selcom::fake(['utilitypayment.query' => 'pending']);

        $this->cmd('selcom:reconcile', ['--older-than' => 0, '--type' => 'payouts'])->assertExitCode(0);

        $fake->assertSent(Operation::UtilityPaymentQuery);
    }
}
