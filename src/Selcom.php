<?php

namespace JasiriLabs\Selcom;

use JasiriLabs\Selcom\Concerns\Checkout;

class Selcom implements Checkout
{

    private SelcomClient $client;

    public function  __construct()
    {
        $this->client = new SelcomClient(config('laravel-selcom'));
    }

    
    public function createOrder(array $params)
    {
        return $this->client->post('/checkout/create-order', $params);

    }

    public function createOrderMinimal(array $params)
    {
        return $this->client->post('/checkout/create-order-minimal', $params);
    }


    public function getOrderStatus(string $orderId)
    {
        return $this->client->get('/checkout/order-status', ['order_id' => $orderId]);
    }


    public function cancelOrder(string $orderId)
    {
        return $this->client->delete('/checkout/cancel-order', ['order_id' => $orderId]);
    }


    public function listOrders(string $fromDate, string $toDate)
    {
        return $this->client->get('/checkout/list-order', ['from_date' => $fromDate, 'to_date' => $toDate]);
    }



}

