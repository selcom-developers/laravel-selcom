<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use SelcomDevelopers\LaravelSelcom\Events\ConflictingResultFlagged;
use SelcomDevelopers\LaravelSelcom\Events\OrderSucceeded;
use SelcomDevelopers\LaravelSelcom\Events\PayoutSucceeded;
use SelcomDevelopers\LaravelSelcom\Exception\ResultMismatchException;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Models\SelcomPayout;
use SelcomDevelopers\Selcom\Amounts;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Result\Result;
use SelcomDevelopers\Selcom\Status;

/**
 * The single place that applies a gateway result to an order or a payout (spec: webhooks.md,
 * "Final-state guard"). Every path that learns an outcome (a direct response, a webhook
 * confirmation, a poll, selcom:reconcile) goes through here, so they cannot disagree.
 *
 * Rules:
 *  - SUCCESS is final. A cancel-confirmed FAILED (orders) is final.
 *  - Once final, nothing changes the state. A conflicting result is ignored and the record is
 *    flagged `needs_review`.
 *  - PENDING never overwrites anything.
 *  - An order becomes `success` only when the gateway's amount (and currency, when it states one)
 *    matches the order. A mismatch keeps it pending and flags it `needs_review`.
 *  - A poll never marks a checkout order failed: order-status FAILED describes the latest
 *    attempt only. Close an order as failed with {@see self::closeOrderAfterCancel()}, after the
 *    gateway confirmed a cancel.
 *  - A payout is marked failed only from the gateway's direct answer to the payment ($source
 *    "submit"), and not for a row last seen as NOT_FOUND or AMBIGUOUS. Any other FAILED is
 *    recorded and flagged for a person, because a rejected query looks the same (spec:
 *    results.md, open questions).
 *  - A result must belong to the record: the right operation, and the same order_id or transid.
 *    Otherwise a ResultMismatchException is thrown and nothing changes.
 *
 * Every change is a compare-and-set under a row lock, so a webhook and a poll that arrive
 * together cannot both win. OrderSucceeded and PayoutSucceeded are fired once, at the transition.
 */
class FinalStateGuard
{
    public const APPLIED = 'applied';
    public const RECORDED = 'recorded';
    public const FLAGGED = 'flagged';
    public const IGNORED = 'ignored';

    public const SOURCE_SUBMIT = 'submit';
    public const SOURCE_QUERY = 'query';

    public function __construct(private readonly Dispatcher $events)
    {
    }

    /**
     * Apply a checkout.order-status (or webhook-confirmed) result to an order.
     *
     * @return string one of the APPLIED, RECORDED, FLAGGED, IGNORED constants
     *
     * @throws ResultMismatchException when the result is not an order-status answer for this order
     */
    public function applyOrderResult(SelcomOrder $order, Result $result): string
    {
        $this->assertBelongs($result, [Operation::CheckoutOrderStatus], $result->orderId, $order->order_id, 'order_id');

        return $this->locked($order, function (SelcomOrder $fresh) use ($result): array {
            $status = $result->status;

            if ($fresh->isFinal()) {
                // Final: only a contradiction is worth anything, and it only raises a flag.
                $contradicts = ($fresh->state === SelcomOrder::SUCCESS && $status === Status::Failed)
                    || ($fresh->state === SelcomOrder::FAILED && $status === Status::Success);

                return $contradicts
                    ? [[], self::FLAGGED, 'A ' . $status->value . ' result arrived for an order that is already ' . $fresh->state . '.']
                    : [[], self::IGNORED, null];
            }

            $changes = $this->observed($result);
            $payment = $result->entry()['payment_status'] ?? null;
            if (is_string($payment)) {
                $changes['payment_status'] = $payment;
            }

            switch ($status) {
                case Status::Success:
                    $mismatch = $this->orderMismatch($fresh, $result);
                    if ($mismatch !== null) {
                        // Paid, but not as ordered: do not fulfil on it. A person looks.
                        $changes['state'] = SelcomOrder::PENDING;

                        return [$changes, self::FLAGGED, $mismatch];
                    }
                    $changes['state'] = SelcomOrder::SUCCESS;
                    $changes['finalized_at'] = now();

                    return [$changes, self::APPLIED, null];
                case Status::NotFound:
                    $changes['state'] = SelcomOrder::NOT_FOUND;

                    return [$changes, self::RECORDED, null];
                case Status::Pending:
                case Status::Ambiguous:
                case Status::Failed:
                    // Never closes the order: FAILED here is the latest attempt, not the order.
                    $changes['state'] = SelcomOrder::PENDING;

                    return [$changes, self::RECORDED, null];
            }
        }, $result);
    }

    /**
     * Close an order as failed. Call this only after checkout.cancel-order succeeded: a completed
     * order cannot be cancelled, so a successful cancel proves it was never paid.
     *
     * @return string one of the APPLIED, FLAGGED, IGNORED constants
     *
     * @throws ResultMismatchException when the result is not a cancel-order answer for this order
     */
    public function closeOrderAfterCancel(SelcomOrder $order, Result $cancelResult): string
    {
        $this->assertBelongs($cancelResult, [Operation::CheckoutCancelOrder], $cancelResult->orderId, $order->order_id, 'order_id');

        if ($cancelResult->status !== Status::Success) {
            return self::IGNORED;
        }

        return $this->locked($order, function (SelcomOrder $fresh) use ($cancelResult): array {
            if ($fresh->state === SelcomOrder::FAILED) {
                return [[], self::IGNORED, null];
            }
            if ($fresh->state === SelcomOrder::SUCCESS) {
                return [[], self::FLAGGED, 'A cancel succeeded for an order that is already success.'];
            }

            return [$this->observed($cancelResult) + [
                'state' => SelcomOrder::FAILED,
                'finalized_at' => now(),
            ], self::APPLIED, null];
        }, $cancelResult);
    }

    /**
     * Apply a payout result.
     *
     * @param self::SOURCE_SUBMIT|self::SOURCE_QUERY $source where the result came from
     *
     * @return string one of the APPLIED, RECORDED, FLAGGED, IGNORED constants
     *
     * @throws ResultMismatchException when the result is not a payment or query answer for this payout
     */
    public function applyPayoutResult(SelcomPayout $payout, Result $result, string $source = self::SOURCE_QUERY): string
    {
        $operations = $payout->kind === SelcomPayout::CASHIN
            ? [Operation::WalletCashinProcess, Operation::WalletCashinQuery]
            : [Operation::UtilityPaymentProcess, Operation::UtilityPaymentQuery];
        $this->assertBelongs($result, $operations, $result->transid, $payout->transid, 'transid');

        return $this->locked($payout, function (SelcomPayout $fresh) use ($result, $source): array {
            $status = $result->status;

            if ($fresh->isFinal()) {
                $contradicts = ($fresh->state === SelcomPayout::SUCCESS && $status === Status::Failed)
                    || ($fresh->state === SelcomPayout::FAILED && $status === Status::Success);
                if ($contradicts) {
                    return [[], self::FLAGGED, 'A ' . $status->value . ' result arrived for a payout that is already ' . $fresh->state . '.'];
                }

                return [[], self::IGNORED, null];
            }

            $changes = $this->observed($result);
            $receipt = $result->entry()['receipt'] ?? null;
            if (is_string($receipt) && $receipt !== '') {
                $changes['receipt'] = $receipt;
            }

            switch ($status) {
                case Status::Success:
                    $changes['state'] = SelcomPayout::SUCCESS;
                    $changes['finalized_at'] = now();

                    return [$changes, self::APPLIED, null];
                case Status::Failed:
                    $unsettled = in_array($fresh->state, [SelcomPayout::NOT_FOUND, SelcomPayout::AMBIGUOUS], true)
                        || in_array($fresh->last_status, [Status::NotFound->value, Status::Ambiguous->value], true);
                    if ($source === self::SOURCE_SUBMIT && !$unsettled) {
                        $changes['state'] = SelcomPayout::FAILED;
                        $changes['finalized_at'] = now();

                        return [$changes, self::APPLIED, null];
                    }
                    // Not a settled direct answer: record it, do not close, and do not let anyone pay
                    // again on this alone.
                    $changes['state'] = $unsettled ? $fresh->state : SelcomPayout::PENDING;

                    return [$changes, self::FLAGGED, $unsettled && $source === self::SOURCE_SUBMIT
                        ? 'A payment answer said FAILED for a payout last seen as ' . ($fresh->last_status ?? $fresh->state)
                            . '. Confirm the outcome before closing it or paying again.'
                        : 'A status query reported FAILED (code ' . ($result->resultCode ?? 'none')
                            . '). Check the code and message, and ask Selcom if unsure, before closing it or paying again.'];
                case Status::NotFound:
                    $changes['state'] = SelcomPayout::NOT_FOUND;

                    return [$changes, self::RECORDED, null];
                case Status::Ambiguous:
                    $changes['state'] = SelcomPayout::AMBIGUOUS;

                    return [$changes, self::RECORDED, null];
                case Status::Pending:
                    $changes['state'] = SelcomPayout::PENDING;

                    return [$changes, self::RECORDED, null];
            }
        }, $result);
    }

    /**
     * @param list<Operation> $operations
     */
    private function assertBelongs(Result $result, array $operations, ?string $resultId, string $recordId, string $field): void
    {
        if (!in_array($result->operation, $operations, true)) {
            throw new ResultMismatchException('A ' . $result->operation->value . ' result cannot be applied to this record.');
        }
        if ($resultId !== $recordId) {
            throw new ResultMismatchException('The result is for another ' . $field . ' than this record.');
        }
    }

    /**
     * The reason an order's SUCCESS must not be accepted, or null when the amount (and the
     * currency, when the gateway states one) match. An order with no stored amount cannot be checked.
     */
    private function orderMismatch(SelcomOrder $order, Result $result): ?string
    {
        if ($order->amount === null) {
            return null;
        }
        $entry = $result->entry();
        $paid = Amounts::tryParse($entry['amount'] ?? null);
        if ($paid === null) {
            return 'The gateway reported the order as paid without an amount that can be checked.';
        }
        if ($paid !== $order->amount) {
            return 'The gateway reports ' . $paid . ' paid for an order of ' . $order->amount . '.';
        }

        $currency = $order->currency;
        $reported = $entry['currency'] ?? null;
        if ($currency !== null) {
            if (is_string($reported) && $reported !== '') {
                if (strtoupper($reported) !== strtoupper($currency)) {
                    return 'The gateway reports currency ' . $reported . ' for an order in ' . $currency . '.';
                }
            } elseif (strtoupper($currency) !== 'TZS') {
                return 'The gateway does not state a currency, and the order is in ' . $currency . '.';
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function observed(Result $result): array
    {
        return [
            'last_status' => $result->status->value,
            'last_resultcode' => $result->resultCode,
            'reference' => $result->reference,
            'last_checked_at' => now(),
        ];
    }

    /**
     * Run a decision under a row lock and write it as a compare-and-set.
     *
     * @template T of Model
     *
     * @param T $record
     * @param \Closure(T): array{0: array<string, mixed>, 1: string, 2: string|null} $decide
     */
    private function locked(Model $record, \Closure $decide, Result $result): string
    {
        $final = $record instanceof SelcomOrder ? SelcomOrder::FINAL_STATES : SelcomPayout::FINAL_STATES;

        /** @var array{0: string, 1: string|null} $verdict */
        $verdict = $record->getConnection()->transaction(function () use ($record, $decide, $final): array {
            $fresh = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            [$changes, $outcome, $flag] = $decide($fresh);

            if ($flag !== null) {
                $changes['needs_review'] = true;
                $changes['review_reason'] = $flag;
            }
            if ($changes !== []) {
                $changes['updated_at'] = now();
                $query = $record->newQuery()->whereKey($record->getKey());
                if (!in_array($fresh->getAttribute('state'), $final, true)) {
                    // Compare-and-set: a state change only lands while the row is still not final.
                    // (A flag on an already-final row changes no state, so it needs no such guard.)
                    $query->whereNotIn('state', $final);
                }
                $query->toBase()->update($this->serialise($record, $changes));
            }

            return [$outcome, $flag];
        });

        $record->refresh();
        if ($verdict[1] !== null) {
            $this->events->dispatch(new ConflictingResultFlagged($record, $result->status, $verdict[1]));
        }
        if ($verdict[0] === self::APPLIED && $record->getAttribute('state') === 'success') {
            $this->events->dispatch($record instanceof SelcomOrder
                ? new OrderSucceeded($record, $result)
                : new PayoutSucceeded($record instanceof SelcomPayout ? $record : throw new \LogicException('Unexpected record.'), $result));
        }

        return $verdict[0];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function serialise(Model $record, array $changes): array
    {
        $out = [];
        foreach ($changes as $column => $value) {
            $out[$column] = $value instanceof \DateTimeInterface
                ? $value->format($record->getDateFormat())
                : $value;
        }

        return $out;
    }
}
