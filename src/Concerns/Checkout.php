<?php

namespace JasiriLabs\Selcom\Concerns;


interface Checkout

{


	public  function createOrder(array $params);




	public function createOrderMinimal(array $params);




	public function deleteOrder(string $orderId);




	public function getOrderStatus(string $orderId);





	public function listOrder(string $fromDate, string $toDate);



}
