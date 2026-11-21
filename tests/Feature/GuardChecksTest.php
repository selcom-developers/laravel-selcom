<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use Illuminate\Support\Facades\Event;
use SelcomDevelopers\LaravelSelcom\Events\ConflictingResultFlagged;
use SelcomDevelopers\LaravelSelcom\Events\OrderSucceeded;
use SelcomDevelopers\LaravelSelcom\Events\PayoutSucceeded;
use SelcomDevelopers\LaravelSelcom\Exception\ResultMismatchException;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Models\SelcomPayout;
use SelcomDevelopers\LaravelSelcom\Services\FinalStateGuard;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Requests;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Result\Result;

/**
 * The guard only accepts a result that belongs to the record, checks the paid amount before it
 * lets an order succeed, and announces each success exactly once.
 */
final class GuardChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationStubs();
    }

    private function guard(): FinalStateGuard
    {
        $guard = $this->app?->make(FinalStateGuard::class);
        \assert($guard instanceof FinalStateGuard);

        return $guard;
    }

    private function orderResult(string $scenario): Result
    {
        Selcom::fake(['checkout.order-status' => $scenario]);

        return Selcom::execute(Requests::for(Operation::CheckoutOrderStatus));
    }

    private function payoutResult(Operation $operation, string $scenario): Result
    {
        Selcom::fake([$operation->value => $scenario]);

        return Selcom::execute(Requests::for($operation));
    }

    private function order(?int $amount, ?string $currency = 'TZS'): SelcomOrder
    {
        return SelcomOrder::query()->create(['order_id' => 'ORD-1001', 'amount' => $amount, 'currency' => $currency]);
    }

    // ---- amount and currency ---------------------------------------------------------------

    public function testAMatchingAmountLetsTheOrderSucceed(): void
    {
        $order = $this->order(5000);

        self::assertSame(FinalStateGuard::APPLIED, $this->guard()->applyOrderResult($order, $this->orderResult('completed')));
        self::assertSame('success', $order->state);
    }

    public function testADifferentAmountKeepsTheOrderPendingAndFlagsIt(): void
    {
        Event::fake([ConflictingResultFlagged::class, OrderSucceeded::class]);
        $order = $this->order(8000);

        self::assertSame(FinalStateGuard::FLAGGED, $this->guard()->applyOrderResult($order, $this->orderResult('completed')));

        self::assertSame('pending', $order->state);
        self::assertTrue($order->needs_review);
        self::assertStringContainsString('5000', (string) $order->review_reason);
        self::assertNull($order->finalized_at);
        Event::assertDispatched(ConflictingResultFlagged::class);
        Event::assertNotDispatched(OrderSucceeded::class);
    }

    public function testACurrencyTheOrderDoesNotUseKeepsTheOrderPending(): void
    {
        $order = $this->order(5000, 'USD');

        self::assertSame(FinalStateGuard::FLAGGED, $this->guard()->applyOrderResult($order, $this->orderResult('completed')));
        self::assertSame('pending', $order->state);
        self::assertTrue($order->needs_review);
    }

    public function testAGatewayCurrencyThatDiffersKeepsTheOrderPending(): void
    {
        Selcom::fake(['checkout.order-status' => ['resultcode' => '000', 'data' => [['payment_status' => 'COMPLETED', 'amount' => '5000', 'currency' => 'USD']]]]);
        $result = Selcom::execute(Requests::for(Operation::CheckoutOrderStatus));
        $order = $this->order(5000, 'TZS');

        self::assertSame(FinalStateGuard::FLAGGED, $this->guard()->applyOrderResult($order, $result));
        self::assertSame('pending', $order->state);
    }

    public function testAMissingAmountInThePaidAnswerKeepsTheOrderPending(): void
    {
        Selcom::fake(['checkout.order-status' => ['resultcode' => '000', 'data' => [['payment_status' => 'COMPLETED']]]]);
        $result = Selcom::execute(Requests::for(Operation::CheckoutOrderStatus));
        $order = $this->order(5000);

        self::assertSame(FinalStateGuard::FLAGGED, $this->guard()->applyOrderResult($order, $result));
        self::assertSame('pending', $order->state);
    }

    public function testAnOrderWithoutAStoredAmountCannotBeChecked(): void
    {
        $order = $this->order(null, null);

        self::assertSame(FinalStateGuard::APPLIED, $this->guard()->applyOrderResult($order, $this->orderResult('completed')));
        self::assertSame('success', $order->state);
    }

    // ---- the result belongs to the record ---------------------------------------------------

    public function testAnOrderRefusesAResultOfTheWrongOperation(): void
    {
        $order = $this->order(5000);
        Selcom::fake(['checkout.cancel-order' => 'success']);
        $cancel = Selcom::execute(Requests::for(Operation::CheckoutCancelOrder));

        $this->expectException(ResultMismatchException::class);
        $this->guard()->applyOrderResult($order, $cancel);
    }

    public function testAnOrderRefusesAResultForAnotherOrder(): void
    {
        $order = SelcomOrder::query()->create(['order_id' => 'ORD-OTHER', 'amount' => 5000]);

        $this->expectException(ResultMismatchException::class);
        $this->guard()->applyOrderResult($order, $this->orderResult('completed'));
    }

    public function testNothingChangesWhenAResultIsRefused(): void
    {
        $order = SelcomOrder::query()->create(['order_id' => 'ORD-OTHER', 'amount' => 5000]);
        try {
            $this->guard()->applyOrderResult($order, $this->orderResult('completed'));
            self::fail('expected a mismatch');
        } catch (ResultMismatchException) {
        }

        self::assertSame('pending', $order->refresh()->state);
        self::assertNull($order->last_status);
    }

    public function testClosingAnOrderNeedsTheCancelOperation(): void
    {
        $order = $this->order(5000);

        $this->expectException(ResultMismatchException::class);
        $this->guard()->closeOrderAfterCancel($order, $this->orderResult('completed'));
    }

    public function testClosingAnOrderNeedsTheSameOrderId(): void
    {
        $order = SelcomOrder::query()->create(['order_id' => 'ORD-OTHER']);
        Selcom::fake(['checkout.cancel-order' => 'success']);
        $cancel = Selcom::execute(Requests::for(Operation::CheckoutCancelOrder));

        $this->expectException(ResultMismatchException::class);
        $this->guard()->closeOrderAfterCancel($order, $cancel);
    }

    public function testAPayoutRefusesAnotherKindOfOperationAndAnotherTransid(): void
    {
        $cashin = SelcomPayout::query()->create(['transid' => 'TXN-CSH-0001', 'kind' => 'cashin', 'state' => 'pending']);
        try {
            $this->guard()->applyPayoutResult($cashin, $this->payoutResult(Operation::UtilityPaymentQuery, 'success'));
            self::fail('a utility result cannot settle a cash-in');
        } catch (ResultMismatchException) {
        }

        $other = SelcomPayout::query()->create(['transid' => 'TXN-OTHER', 'kind' => 'utility', 'state' => 'pending']);
        $this->expectException(ResultMismatchException::class);
        $this->guard()->applyPayoutResult($other, $this->payoutResult(Operation::UtilityPaymentQuery, 'success'));
    }

    // ---- one success event per record --------------------------------------------------------

    public function testOrderSucceededFiresOnceAtTheTransition(): void
    {
        Event::fake([OrderSucceeded::class]);
        $order = $this->order(5000);
        $guard = $this->guard();

        $guard->applyOrderResult($order, $this->orderResult('completed'));
        $guard->applyOrderResult($order, $this->orderResult('completed')); // a re-check, a redelivery, a job retry
        $guard->applyOrderResult($order, $this->orderResult('completed'));

        Event::assertDispatchedTimes(OrderSucceeded::class, 1);
    }

    public function testPayoutSucceededFiresOnceAtTheTransition(): void
    {
        Event::fake([PayoutSucceeded::class]);
        $payout = SelcomPayout::query()->create(['transid' => 'TXN-UTL-0001', 'kind' => 'utility', 'state' => 'pending']);
        $guard = $this->guard();

        $guard->applyPayoutResult($payout, $this->payoutResult(Operation::UtilityPaymentQuery, 'success'));
        $guard->applyPayoutResult($payout, $this->payoutResult(Operation::UtilityPaymentQuery, 'success'));

        Event::assertDispatchedTimes(PayoutSucceeded::class, 1);
    }

    public function testNoSuccessEventWhenAnOrderIsClosedAsFailed(): void
    {
        Event::fake([OrderSucceeded::class]);
        $order = $this->order(5000);
        Selcom::fake(['checkout.cancel-order' => 'success']);

        $this->guard()->closeOrderAfterCancel($order, Selcom::execute(Requests::for(Operation::CheckoutCancelOrder)));

        self::assertSame('failed', $order->state);
        Event::assertNotDispatched(OrderSucceeded::class);
    }

    // ---- a submit FAILED for a row that was not settled ---------------------------------------

    public function testASubmitFailedForARowLastSeenNotFoundOrAmbiguousIsFlaggedNotFinalised(): void
    {
        foreach (['not-found' => SelcomPayout::NOT_FOUND, 'ambiguous' => SelcomPayout::AMBIGUOUS] as $scenario => $state) {
            $payout = SelcomPayout::query()->create(['transid' => 'TXN-UTL-0001', 'kind' => 'utility', 'state' => SelcomPayout::PENDING]);
            $guard = $this->guard();
            $guard->applyPayoutResult($payout, $this->payoutResult(Operation::UtilityPaymentQuery, $scenario));
            self::assertSame($state, $payout->state);

            $outcome = $guard->applyPayoutResult(
                $payout,
                $this->payoutResult(Operation::UtilityPaymentProcess, 'failure'),
                FinalStateGuard::SOURCE_SUBMIT,
            );

            self::assertSame(FinalStateGuard::FLAGGED, $outcome, $scenario);
            self::assertSame($state, $payout->state, $scenario . ' stays as it was');
            self::assertTrue($payout->needs_review);
            self::assertNull($payout->finalized_at);
            $payout->delete();
        }
    }
}
