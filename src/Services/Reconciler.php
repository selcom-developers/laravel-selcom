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
    ) {
    }

    /**
     * @return list<array{type: string, id: string, status: string|null, outcome: string, error: string|null}>
     */
    public function reconcileOrders(int $olderThanMinutes, int $limit, bool $dryRun = false): array
    {
        $class = $this->modelClass('order', SelcomOrder::class);
        $rows = [];
        foreach ($this->due($class, SelcomOrder::RECONCILABLE_STATES, $olderThanMinutes, $limit) as $order) {
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
        foreach ($this->due($class, SelcomPayout::RECONCILABLE_STATES, $olderThanMinutes, $limit) as $payout) {
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
    private function due(string $class, array $states, int $olderThanMinutes, int $limit): iterable
    {
        return $class::query()
            ->whereIn('state', $states)
            ->where('updated_at', '<=', now()->subMinutes($olderThanMinutes))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
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
