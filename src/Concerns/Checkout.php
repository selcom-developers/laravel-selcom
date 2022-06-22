<?php

namespace JasiriLabs\Selcom\Concerns;


interface Checkout

{


	public  function createOrder(array $params);




	public function createOrderMinimal(array $params);




	public function cancelOrder(string $orderId);




	public function getOrderStatus(string $orderId);





	public function listOrders(string $fromDate, string $toDate);



}
