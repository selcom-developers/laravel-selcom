<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use Illuminate\Support\Facades\Event;
use SelcomDevelopers\LaravelSelcom\Events\ConflictingResultFlagged;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Models\SelcomPayout;
use SelcomDevelopers\LaravelSelcom\Services\FinalStateGuard;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Requests;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Result\Result;

final class FinalStateGuardTest extends TestCase
{
    private FinalStateGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationStubs();
        $guard = $this->app?->make(FinalStateGuard::class);
        \assert($guard instanceof FinalStateGuard);
        $this->guard = $guard;
    }

    private function order(string $scenario): Result
    {
        Selcom::fake(['checkout.order-status' => $scenario]);

        return Selcom::execute(Requests::for(Operation::CheckoutOrderStatus));
    }

    private function payout(Operation $op, string $scenario): Result
    {
        Selcom::fake([$op->value => $scenario]);

        return Selcom::execute(Requests::for($op));
    }

    private function newOrder(): SelcomOrder
    {
        return SelcomOrder::query()->create(['order_id' => 'ORD-1001', 'amount' => 8000, 'currency' => 'TZS']);
    }

    private function newPayout(string $state = SelcomPayout::PENDING): SelcomPayout
    {
        return SelcomPayout::query()->create(['transid' => 'TXN-UTL-0001', 'kind' => SelcomPayout::UTILITY, 'amount' => 8000, 'state' => $state]);
    }

    public function testASuccessfulOrderStatusIsFinal(): void
    {
        $order = $this->newOrder();

        self::assertSame(FinalStateGuard::APPLIED, $this->guard->applyOrderResult($order, $this->order('completed')));
        self::assertSame('success', $order->state);
        self::assertNotNull($order->finalized_at);
        self::assertSame('COMPLETED', $order->payment_status);
    }

    public function testAPollNeverMarksAnOrderFailed(): void
    {
        $order = $this->newOrder();

        foreach (['cancelled', 'rejected', 'usercancelled'] as $scenario) {
            $this->guard->applyOrderResult($order, $this->order($scenario));
            self::assertSame('pending', $order->state, $scenario . ' describes the latest attempt only');
            self::assertSame('FAILED', $order->last_status);
            self::assertNull($order->finalized_at);
        }
    }

    public function testAFailedAfterASuccessIsIgnoredAndFlagged(): void
    {
        Event::fake([ConflictingResultFlagged::class]);
        $guard = $this->app?->make(FinalStateGuard::class);
        \assert($guard instanceof FinalStateGuard);
        $this->guard = $guard;
        $order = $this->newOrder();
        $this->guard->applyOrderResult($order, $this->order('completed'));

        self::assertSame(FinalStateGuard::FLAGGED, $this->guard->applyOrderResult($order, $this->order('cancelled')));
        self::assertSame('success', $order->state);
        self::assertTrue($order->needs_review);
        Event::assertDispatched(ConflictingResultFlagged::class);
    }

    public function testPendingNeverOverwritesAFinalState(): void
    {
        $order = $this->newOrder();
        $this->guard->applyOrderResult($order, $this->order('completed'));

        self::assertSame(FinalStateGuard::IGNORED, $this->guard->applyOrderResult($order, $this->order('pending')));
        self::assertSame('success', $order->state);
        self::assertFalse($order->needs_review);
    }

    public function testAnOrderIsClosedAsFailedOnlyAfterAConfirmedCancel(): void
    {
        $order = $this->newOrder();
        Selcom::fake(['checkout.cancel-order' => ['failure', 'success']]);

        $failedCancel = Selcom::execute(Requests::for(Operation::CheckoutCancelOrder));
        self::assertSame(FinalStateGuard::IGNORED, $this->guard->closeOrderAfterCancel($order, $failedCancel));
        self::assertSame('pending', $order->state);

        $cancel = Selcom::execute(Requests::for(Operation::CheckoutCancelOrder));
        self::assertSame(FinalStateGuard::APPLIED, $this->guard->closeOrderAfterCancel($order, $cancel));
        self::assertSame('failed', $order->state);

        // And a later success does not reopen it: it is flagged for a person.
        self::assertSame(FinalStateGuard::FLAGGED, $this->guard->applyOrderResult($order, $this->order('completed')));
        self::assertSame('failed', $order->state);
    }

    public function testAnOrderTheGatewayDoesNotKnowIsNotFinal(): void
    {
        $order = $this->newOrder();
        $this->guard->applyOrderResult($order, $this->order('empty-data'));
        self::assertSame('not_found', $order->state);
        self::assertFalse($order->isFinal());

        $this->guard->applyOrderResult($order, $this->order('completed'));
        self::assertSame('success', $order->state);
    }

    public function testAPayoutSucceedsFromAQueryAndKeepsItsReceipt(): void
    {
        $payout = $this->newPayout();
        $result = $this->payout(Operation::UtilityPaymentQuery, 'success');

        self::assertSame(FinalStateGuard::APPLIED, $this->guard->applyPayoutResult($payout, $result));
        self::assertSame('success', $payout->state);
        self::assertSame('SUCCESS', $payout->last_status);
    }

    public function testAPayoutIsFailedFromTheDirectAnswerButNotFromAQuery(): void
    {
        $query = $this->newPayout();
        $outcome = $this->guard->applyPayoutResult($query, $this->payout(Operation::UtilityPaymentQuery, 'failure'), FinalStateGuard::SOURCE_QUERY);
        self::assertSame(FinalStateGuard::FLAGGED, $outcome);
        self::assertSame('pending', $query->state);
        self::assertTrue($query->needs_review);

        $direct = SelcomPayout::query()->create(['transid' => 'TXN-UTL-0002', 'kind' => 'utility', 'state' => SelcomPayout::SUBMITTING]);
        $outcome = $this->guard->applyPayoutResult($direct, $this->payout(Operation::UtilityPaymentProcess, 'failure'), FinalStateGuard::SOURCE_SUBMIT);
        self::assertSame(FinalStateGuard::APPLIED, $outcome);
        self::assertSame('failed', $direct->state);
    }

    public function testPayoutStatesFollowTheTypedStatuses(): void
    {
        $payout = $this->newPayout(SelcomPayout::SUBMITTING);

        $this->guard->applyPayoutResult($payout, $this->payout(Operation::UtilityPaymentQuery, 'ambiguous'));
        self::assertSame('ambiguous', $payout->state);
        $this->guard->applyPayoutResult($payout, $this->payout(Operation::UtilityPaymentQuery, 'pending'));
        self::assertSame('pending', $payout->state);
        $this->guard->applyPayoutResult($payout, $this->payout(Operation::UtilityPaymentQuery, 'not-found'));
        self::assertSame('not_found', $payout->state);
        self::assertFalse($payout->isFinal());
    }

    public function testAFinalPayoutNeverChanges(): void
    {
        $payout = $this->newPayout();
        $this->guard->applyPayoutResult($payout, $this->payout(Operation::UtilityPaymentQuery, 'success'));

        self::assertSame(FinalStateGuard::IGNORED, $this->guard->applyPayoutResult($payout, $this->payout(Operation::UtilityPaymentQuery, 'pending')));
        self::assertSame(FinalStateGuard::FLAGGED, $this->guard->applyPayoutResult($payout, $this->payout(Operation::UtilityPaymentProcess, 'failure'), FinalStateGuard::SOURCE_SUBMIT));
        self::assertSame('success', $payout->state);
    }

    public function testTheIdsAreUnique(): void
    {
        $this->newOrder();
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->newOrder();
    }
}
