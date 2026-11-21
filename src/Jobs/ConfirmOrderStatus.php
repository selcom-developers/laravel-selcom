<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Schema;
use SelcomDevelopers\LaravelSelcom\Events\OrderStatusConfirmed;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Services\FinalStateGuard;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;

/**
 * Step 6 of the webhook handling: after the delivery was stored and acknowledged, ask the gateway
 * what really happened (checkout.order-status is a read-only query, safe to repeat), fire
 * OrderStatusConfirmed, and, when the orders table exists and has the order, apply the result
 * through the final-state guard.
 *
 * It never fulfils anything and never marks an order failed: that is for your listener and your
 * cancel flow.
 */
final class ConfirmOrderStatus implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180];

    public function __construct(
        public readonly string $orderId,
        public readonly ?string $transid = null,
    ) {
    }

    public function handle(Client $client, Dispatcher $events, FinalStateGuard $guard): void
    {
        $result = $client->orderStatus(new OrderStatusRequest($this->orderId));

        $events->dispatch(new OrderStatusConfirmed($this->orderId, $result));

        $class = config('selcom.models.order', SelcomOrder::class);
        if (!is_string($class) || !is_subclass_of($class, SelcomOrder::class) && $class !== SelcomOrder::class) {
            return;
        }
        $probe = new $class();
        if (!Schema::connection($probe->getConnectionName())->hasTable($probe->getTable())) {
            return;
        }
        $order = $class::query()->where('order_id', $this->orderId)->first();
        if ($order instanceof SelcomOrder) {
            $guard->applyOrderResult($order, $result);
        }
    }
}
