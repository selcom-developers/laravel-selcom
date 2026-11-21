<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use Illuminate\Testing\PendingCommand;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Models\SelcomPayout;
use SelcomDevelopers\LaravelSelcom\Testing\Scenarios;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\Selcom\Operation;

final class CommandsTest extends TestCase
{
    /** Operations that move money or write: reconcile must never send any of these. */
    private const NEVER = [
        Operation::CheckoutCreateOrder, Operation::CheckoutCreateOrderMinimal, Operation::CheckoutWalletPayment,
        Operation::CheckoutCardPayment, Operation::CheckoutCancelOrder, Operation::CheckoutDeleteCard,
        Operation::UtilityPaymentProcess, Operation::WalletCashinProcess,
    ];

    /**
     * @param array<string, mixed> $parameters
     */
    private function cmd(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        \assert($pending instanceof PendingCommand);

        return $pending;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationStubs();
    }

    private function stale(SelcomOrder|SelcomPayout $record, int $minutes = 10): void
    {
        $record->newQuery()->whereKey($record->getKey())->toBase()->update(['updated_at' => now()->subMinutes($minutes)]);
    }

    public function testStatusQueriesAnOrderAndPrintsTheVerdict(): void
    {
        $fake = Selcom::fake(['checkout.order-status' => 'completed']);

        $this->cmd('selcom:status', ['id' => 'ORD-1001'])
            ->expectsOutputToContain('SUCCESS')
            ->assertExitCode(0);

        $fake->assertSent(Operation::CheckoutOrderStatus, static fn ($r): bool => $r->param('order_id') === 'ORD-1001');
        $fake->assertSentCount(1);
    }

    public function testStatusQueriesATransactionByType(): void
    {
        $fake = Selcom::fake(['walletcashin.query' => 'pending', 'utilitypayment.query' => 'not-found']);

        $this->cmd('selcom:status', ['id' => 'TXN-CSH-0001', '--type' => 'cashin'])->expectsOutputToContain('PENDING')->assertExitCode(0);
        $this->cmd('selcom:status', ['id' => 'TXN-UTL-0001', '--type' => 'utility'])->expectsOutputToContain('NOT_FOUND')->assertExitCode(0);

        $fake->assertSent(Operation::WalletCashinQuery);
        $fake->assertSent(Operation::UtilityPaymentQuery);
    }

    public function testStatusExplainsATransportErrorAndNeverResends(): void
    {
        $fake = Selcom::fake(['checkout.order-status' => Scenarios::unknownOutcome()]);

        $this->cmd('selcom:status', ['id' => 'ORD-1001'])
            ->expectsOutputToContain('unknown')
            ->assertExitCode(1);
        $fake->assertSentCount(1);
    }

    public function testStatusRejectsAnUnknownType(): void
    {
        $fake = Selcom::fake();
        $this->cmd('selcom:status', ['id' => 'x', '--type' => 'bogus'])->assertExitCode(2);
        $fake->assertNothingSent();
    }

    public function testReconcileQueriesQuietInProgressRecordsAndOnlyQueries(): void
    {
        $order = SelcomOrder::query()->create(['order_id' => 'ORD-1001']);
        $utility = SelcomPayout::query()->create(['transid' => 'TXN-UTL-0001', 'kind' => 'utility', 'state' => 'pending']);
        $cashin = SelcomPayout::query()->create(['transid' => 'TXN-CSH-0001', 'kind' => 'cashin', 'state' => 'ambiguous']);
        $fresh = SelcomOrder::query()->create(['order_id' => 'ORD-FRESH']);
        $done = SelcomOrder::query()->create(['order_id' => 'ORD-DONE', 'state' => 'success']);
        foreach ([$order, $utility, $cashin, $done] as $r) {
            $this->stale($r);
        }

        $fake = Selcom::fake([
            'checkout.order-status' => 'completed',
            'utilitypayment.query' => 'success',
            'walletcashin.query' => 'pending',
        ]);

        $this->cmd('selcom:reconcile', ['--older-than' => 3])->assertExitCode(0);

        self::assertSame('success', $order->refresh()->state);
        self::assertSame('success', $utility->refresh()->state);
        self::assertSame('pending', $cashin->refresh()->state);
        self::assertSame('pending', $fresh->refresh()->state);
        self::assertNull($fresh->last_checked_at, 'a record touched under N minutes ago is left alone');
        $fake->assertSentCount(3);
        foreach (self::NEVER as $op) {
            $fake->assertNotSent($op);
        }
    }

    public function testReconcileLeavesARecordAloneWhenTheQueryGetsNoAnswer(): void
    {
        $payout = SelcomPayout::query()->create(['transid' => 'TXN-UTL-0001', 'kind' => 'utility', 'state' => 'ambiguous']);
        $this->stale($payout);
        $fake = Selcom::fake(['utilitypayment.query' => Scenarios::unknownOutcome()]);

        $this->cmd('selcom:reconcile')->assertExitCode(1);

        self::assertSame('ambiguous', $payout->refresh()->state);
        self::assertFalse($payout->needs_review);
        $fake->assertSentCount(1);
        $fake->assertNotSent(Operation::UtilityPaymentProcess);
    }

    public function testReconcileNeverTurnsAFailedQueryIntoAFailedPayout(): void
    {
        $payout = SelcomPayout::query()->create(['transid' => 'TXN-UTL-0001', 'kind' => 'utility', 'state' => 'pending']);
        $this->stale($payout);
        Selcom::fake(['utilitypayment.query' => 'failed']);

        $this->cmd('selcom:reconcile')->assertExitCode(0);

        self::assertSame('pending', $payout->refresh()->state);
        self::assertTrue($payout->needs_review);
    }

    public function testReconcileDryRunQueriesNothing(): void
    {
        $order = SelcomOrder::query()->create(['order_id' => 'ORD-1001']);
        $this->stale($order);
        $fake = Selcom::fake();

        $this->cmd('selcom:reconcile', ['--dry-run' => true])->expectsOutputToContain('would-query')->assertExitCode(0);

        $fake->assertNothingSent();
    }

    public function testReconcileRespectsTypeAndLimit(): void
    {
        foreach (['ORD-1', 'ORD-2', 'ORD-3'] as $id) {
            $this->stale(SelcomOrder::query()->create(['order_id' => $id]));
        }
        $this->stale(SelcomPayout::query()->create(['transid' => 'TXN-1', 'kind' => 'utility', 'state' => 'pending']));
        $fake = Selcom::fake(['checkout.order-status' => 'pending']);

        $this->cmd('selcom:reconcile', ['--type' => 'orders', '--limit' => 2])->assertExitCode(0);

        $fake->assertSentCount(2);
        $fake->assertNotSent(Operation::UtilityPaymentQuery);
        $this->cmd('selcom:reconcile', ['--type' => 'nope'])->assertExitCode(2);
    }
}
