<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use SelcomDevelopers\LaravelSelcom\Events\RecordReconciled;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Models\SelcomPayout;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Exception\SelcomException;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;
use SelcomDevelopers\Selcom\Request\UtilityQueryRequest;
use SelcomDevelopers\Selcom\Request\WalletCashinQueryRequest;
use SelcomDevelopers\Selcom\Result\Result;

/**
 * Finds in-progress records that have been quiet for N minutes and asks the gateway about them.
 *
 * QUERY ONLY. This class has no code path that submits a payment, an order, a cancel or any other
 * write: it calls orderStatus, utilityQuery and walletCashinQuery, nothing else. The answer is applied
 * through the FinalStateGuard. Deciding what to do about a NOT_FOUND payout (the only case where the
 * same transid may be submitted again) or a stuck one is left to a person or to your own code.
 */
final class Reconciler
{
    public function __construct(
        private readonly Client $client,
        private readonly FinalStateGuard $guard,
        private readonly Dispatcher $events,
        private readonly ConfigRepository $config,
        private readonly \SelcomDevelopers\Selcom\Config $selcom,
    ) {
    }

    /**
     * @return list<array{type: string, id: string, status: string|null, outcome: string, error: string|null}>
     */
    public function reconcileOrders(int $olderThanMinutes, int $limit, bool $dryRun = false): array
    {
        $class = $this->modelClass('order', SelcomOrder::class);
        if (!$dryRun) {
            $this->handOverAbandonedOrders($class);
        }
        $rows = [];
        foreach ($this->due($class, SelcomOrder::RECONCILABLE_STATES, $olderThanMinutes, $limit, false) as $order) {
            \assert($order instanceof SelcomOrder);
            $rows[] = $this->one('order', $order->order_id, $dryRun, function () use ($order): array {
                $result = $this->client->orderStatus(new OrderStatusRequest($order->order_id));

                return $this->settle($order, $result, $this->guard->applyOrderResult($order, $result));
            });
        }

        return $rows;
    }

    /**
     * @return list<array{type: string, id: string, status: string|null, outcome: string, error: string|null}>
     */
    public function reconcilePayouts(int $olderThanMinutes, int $limit, bool $dryRun = false): array
    {
        $class = $this->modelClass('payout', SelcomPayout::class);
        $rows = [];
        foreach ($this->due($class, SelcomPayout::RECONCILABLE_STATES, $olderThanMinutes, $limit, true) as $payout) {
            \assert($payout instanceof SelcomPayout);
            $rows[] = $this->one($payout->kind, $payout->transid, $dryRun, function () use ($payout): array {
                $result = $payout->kind === SelcomPayout::CASHIN
                    ? $this->client->walletCashinQuery(new WalletCashinQueryRequest($payout->transid))
                    : $this->client->utilityQuery(new UtilityQueryRequest($payout->transid));

                return $this->settle($payout, $result, $this->guard->applyPayoutResult($payout, $result, FinalStateGuard::SOURCE_QUERY));
            });
        }

        return $rows;
    }

    /**
     * @param \Closure(): array{0: Result, 1: string} $query
     *
     * @return array{type: string, id: string, status: string|null, outcome: string, error: string|null}
     */
    private function one(string $type, string $id, bool $dryRun, \Closure $query): array
    {
        if ($dryRun) {
            return ['type' => $type, 'id' => $id, 'status' => null, 'outcome' => 'would-query', 'error' => null];
        }

        try {
            [$result, $outcome] = $query();
        } catch (SelcomException $e) {
            // No answer (or an answer without a verdict): leave the record exactly as it is, so the next
            // run asks again. Never "fail" a record because a query failed.
            return ['type' => $type, 'id' => $id, 'status' => null, 'outcome' => 'error', 'error' => $e::class . ': ' . $e->getMessage()];
        } catch (\Throwable $e) {
            // The answer was applied, but a listener (or anything after it) threw. One record's
            // failure must not stop the run: report it and carry on with the next.
            report($e);

            return ['type' => $type, 'id' => $id, 'status' => null, 'outcome' => 'error', 'error' => $e::class . ': ' . $e->getMessage()];
        }

        return ['type' => $type, 'id' => $id, 'status' => $result->status->value, 'outcome' => $outcome, 'error' => null];
    }

    /**
     * @return array{0: Result, 1: string}
     */
    private function settle(Model $record, Result $result, string $outcome): array
    {
        $this->events->dispatch(new RecordReconciled($record, $result, $outcome));

        return [$result, $outcome];
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $class
     * @param list<string> $states
     *
     * @return iterable<int, T>
     */
    private function due(string $class, array $states, int $olderThanMinutes, int $limit, bool $payouts): iterable
    {
        $quietSince = now()->subMinutes($olderThanMinutes);
        $query = $class::query()
            ->whereIn('state', $states)
            // A record flagged for review has been handed to a person: it is not polled again.
            ->where('needs_review', false)
            ->where('updated_at', '<=', $quietSince);

        if ($payouts) {
            // A payout still being submitted may have a request in flight: give it the whole payment
            // timeout (connect + read) plus a margin before asking about it.
            $query->where(function ($q) use ($quietSince): void {
                $q->where('state', '!=', SelcomPayout::SUBMITTING)
                    ->orWhere('updated_at', '<=', min($quietSince, $this->submittingCutoff()));
            });
        }

        return $query->orderBy('updated_at')->limit($limit)->get();
    }

    private function submittingCutoff(): \Illuminate\Support\Carbon
    {
        $payment = $this->selcom->timeouts->payment;
        $margin = $this->config->get('selcom.reconcile.submitting_margin_seconds', 30);
        $seconds = (int) ceil($payment->connectSeconds + $payment->readSeconds) + (is_int($margin) && $margin >= 0 ? $margin : 30);

        return now()->subSeconds($seconds);
    }

    /**
     * Orders are polled for a bounded time: not-found ones for `reconcile.not_found_max_age_minutes`,
     * pending ones for `reconcile.pending_max_age_minutes` (both from creation). After that they are
     * flagged for a person and no longer polled, so abandoned rows cannot crowd out real ones.
     *
     * @param class-string<SelcomOrder> $class
     */
    private function handOverAbandonedOrders(string $class): void
    {
        $this->handOver($class, SelcomOrder::NOT_FOUND, 'not_found_max_age_minutes', 60,
            'The gateway had no record of this order for %d minutes. Check whether it was ever created.');
        $this->handOver($class, SelcomOrder::PENDING, 'pending_max_age_minutes', 2880,
            'This order was still unpaid after %d minutes. Check it, then close it (cancel it first) or leave it.');
    }

    /**
     * @param class-string<SelcomOrder> $class
     */
    private function handOver(string $class, string $state, string $setting, int $default, string $reason): void
    {
        $minutes = $this->config->get('selcom.reconcile.' . $setting, $default);
        $minutes = is_int($minutes) && $minutes > 0 ? $minutes : $default;

        $class::query()
            ->where('state', $state)
            ->where('needs_review', false)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->update([
                'needs_review' => true,
                'review_reason' => sprintf($reason, $minutes),
            ]);
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $default
     *
     * @return class-string<T>
     */
    private function modelClass(string $key, string $default): string
    {
        $class = $this->config->get('selcom.models.' . $key);

        return is_string($class) && is_a($class, $default, true) ? $class : $default;
    }
}
