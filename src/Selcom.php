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
       $response =  $this->client->post('/checkout/create-order', $params);

        $paymentGatewayUrl = base64_decode(json_decode($response->body(), true)['data'][0]['payment_gateway_url']);

        return [
            'payment_gateway_url' => $paymentGatewayUrl,
        ];
    }

    public function createOrderMinimal(array $params)
    {
        $response =  $this->client->post('/checkout/create-order-minimal', $params);

        $paymentGatewayUrl = base64_decode(json_decode($response->body(), true)['data'][0]['payment_gateway_url']);

        return [
            'payment_gateway_url' => $paymentGatewayUrl,
        ];
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

