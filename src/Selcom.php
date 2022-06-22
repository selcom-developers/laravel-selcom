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
        $responseBody =  $this->client->post('/checkout/create-order', $params);

        return $responseBody['data'][0];

    }

    public function createOrderMinimal(array $params)
    {
        $responseBody =  $this->client->post('/checkout/create-order-minimal', $params);

        return $responseBody['data'][0];
    }


    public function getOrderStatus(string $orderId)
    {
        $response =  $this->client->get('/checkout/order-status', ['order_id' => $orderId]);

        return base64_decode(json_decode($response->body(), true));
    }


    public function deleteOrder(string $orderId)
    {
        $response =  $this->client->delete('/checkout/cancel-order', ['order_id' => $orderId]);

        return base64_decode(json_decode($response->body(), true));
    }


    public function listOrder(string $fromDate, string $toDate)
    {
        $response =  $this->client->get('/checkout/list-order', ['from_date' => $fromDate, 'to_date' => $toDate]);

        return base64_decode(json_decode($response->body(), true));
    }



}

