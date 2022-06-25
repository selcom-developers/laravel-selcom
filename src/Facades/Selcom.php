<?php

namespace JasiriLabs\Selcom\Facades;

use Illuminate\Support\Facades\Facade;

/**
 *
 * @method static \JasiriLabs\Selcom\Concerns\Checkout createOrder(array $params)
 * @method static \JasiriLabs\Selcom\Concerns\Checkout createOrderMinimal(array $params)
 * @method static \JasiriLabs\Selcom\Concerns\Checkout getOrderStatus(string $orderId)
 * @method static \JasiriLabs\Selcom\Concerns\Checkout cancelOrder(string $orderId)
 * @method static \JasiriLabs\Selcom\Concerns\Checkout listOrders(string $fromDate, string $toDate)
 *
 *
 * @see \Jasirilabs\Selcom\Selcom
 *
 */
class Selcom extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'laravel-selcom';
    }
}
