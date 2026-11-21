<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Schema;
use SelcomDevelopers\LaravelSelcom\Events\OrderStatusConfirmed;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Services\FinalStateGuard;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Exception\TransportException;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;
use SelcomDevelopers\Selcom\Status;

/**
 * Step 6 of the webhook handling: after the delivery was stored and acknowledged, ask the gateway
 * what really happened (checkout.order-status is a read-only query, safe to repeat), apply the
 * answer through the final-state guard when the orders table exists and has the order, then fire
 * OrderStatusConfirmed with the guard's outcome.
 *
 * While the order is PENDING or AMBIGUOUS the job releases itself back to the queue and asks
 * again every `selcom.webhook.confirm_interval_seconds` (the spec's 3 minutes), until
 * `selcom.webhook.confirm_window_minutes` have passed. After that selcom:reconcile takes over.
 *
 * It never fulfils anything and never marks an order failed. Fulfil on OrderSucceeded.
 */
final class ConfirmOrderStatus implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Unlimited attempts, bounded by retryUntil(). */
    public int $tries = 0;

    /**
     * One confirmation per order at a time: repeated deliveries for the same order (an unsigned
     * post is cheap to repeat) cannot fan out into parallel polls. The lock lasts at most the window.
     */
    public function uniqueId(): string
    {
        return 'selcom-confirm:' . $this->orderId;
    }

    public function uniqueFor(): int
    {
        $minutes = config('selcom.webhook.confirm_window_minutes', 30);

        return (is_int($minutes) && $minutes > 0 ? $minutes : 30) * 60;
    }

    /** Seconds to wait before retrying after an exception (the spec's 3 minutes by default). */
    public function backoff(): int
    {
        $interval = config('selcom.webhook.confirm_interval_seconds', 180);

        return is_int($interval) && $interval > 0 ? $interval : 180;
    }

    public function __construct(
        public readonly string $orderId,
        public readonly ?string $transid = null,
    ) {
    }

    public function retryUntil(): \DateTimeInterface
    {
        $minutes = config('selcom.webhook.confirm_window_minutes', 30);

        return now()->addMinutes(is_int($minutes) && $minutes > 0 ? $minutes : 30);
    }

    public function handle(Client $client, Dispatcher $events, FinalStateGuard $guard): void
    {
        try {
            $this->check($client, $events, $guard);
        } catch (TransportException $e) {
            // No answer: asking again later is the point. The queue retries after backoff().
            throw $e;
        } catch (\Throwable $e) {
            // Anything else (a result for another record, a configuration problem, a bug) will not
            // fix itself by running again.
            if ($this->job === null) {
                throw $e;
            }
            $this->fail($e);
        }
    }

    private function check(Client $client, Dispatcher $events, FinalStateGuard $guard): void
    {
        $result = $client->orderStatus(new OrderStatusRequest($this->orderId));

        $outcome = null;
        $class = config('selcom.models.order', SelcomOrder::class);
        if (is_string($class) && is_a($class, SelcomOrder::class, true)) {
            $probe = new $class();
            if (Schema::connection($probe->getConnectionName())->hasTable($probe->getTable())) {
                $order = $class::query()->where('order_id', $this->orderId)->first();
                if ($order instanceof SelcomOrder) {
                    $outcome = $guard->applyOrderResult($order, $result);
                }
            }
        }

        $events->dispatch(new OrderStatusConfirmed($this->orderId, $result, $outcome));

        if (($result->status === Status::Pending || $result->status === Status::Ambiguous) && $this->job !== null) {
            $this->release($this->backoff());
        }
    }
}
