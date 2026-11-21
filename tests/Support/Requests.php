<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Support;

use SelcomDevelopers\Selcom\Currency;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Request\Address;
use SelcomDevelopers\Selcom\Request\CancelOrderRequest;
use SelcomDevelopers\Selcom\Request\CardPaymentRequest;
use SelcomDevelopers\Selcom\Request\CreateOrderMinimalRequest;
use SelcomDevelopers\Selcom\Request\CreateOrderRequest;
use SelcomDevelopers\Selcom\Request\DeleteCardRequest;
use SelcomDevelopers\Selcom\Request\ListOrdersRequest;
use SelcomDevelopers\Selcom\Request\OperationRequest;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;
use SelcomDevelopers\Selcom\Request\PaymentMethod;
use SelcomDevelopers\Selcom\Request\StoredCardsRequest;
use SelcomDevelopers\Selcom\Request\UtilityLookupRequest;
use SelcomDevelopers\Selcom\Request\UtilityPaymentRequest;
use SelcomDevelopers\Selcom\Request\UtilityQueryRequest;
use SelcomDevelopers\Selcom\Request\VendorBalanceRequest;
use SelcomDevelopers\Selcom\Request\WalletCashinQueryRequest;
use SelcomDevelopers\Selcom\Request\WalletCashinRequest;
use SelcomDevelopers\Selcom\Request\WalletNameLookupRequest;
use SelcomDevelopers\Selcom\Request\WalletPaymentRequest;

/**
 * One valid request per operation, built from made-up test data.
 */
final class Requests
{
    public static function for(Operation $operation): OperationRequest
    {
        return match ($operation) {
            Operation::CheckoutCreateOrder => new CreateOrderRequest(
                'ORD-1001',
                'test.buyer@example.com',
                'Test Buyer',
                '255700000001',
                8000,
                Currency::TZS,
                [PaymentMethod::All],
                new Address('Test', 'Buyer', '1 Test Street', 'Dar es Salaam', 'Dar es Salaam', '11101', 'TZ', '255700000001'),
                1,
                webhookUrl: 'https://shop.example.com/selcom/webhook/token',
            ),
            Operation::CheckoutCreateOrderMinimal => new CreateOrderMinimalRequest(
                'ORD-1002',
                'test.buyer@example.com',
                'Test Buyer',
                '255700000001',
                8000,
                Currency::TZS,
                1,
            ),
            Operation::CheckoutWalletPayment => new WalletPaymentRequest('TXN-WLT-0001', 'ORD-1002', '255700000001'),
            Operation::CheckoutCardPayment => new CardPaymentRequest('TXN-CRD-0001', 'ORD-1001', 'CARDTOKEN-TEST-0001', 'USER-1', 'GBU-TEST-0001'),
            Operation::CheckoutOrderStatus => new OrderStatusRequest('ORD-1001'),
            Operation::CheckoutCancelOrder => new CancelOrderRequest('ORD-1001'),
            Operation::CheckoutListOrders => new ListOrdersRequest('2026-11-01', '2026-11-21'),
            Operation::CheckoutStoredCards => new StoredCardsRequest('GBU-TEST-0001', 'USER-1'),
            Operation::CheckoutDeleteCard => new DeleteCardRequest(23, 'GBU-TEST-0001'),
            Operation::UtilityPaymentProcess => new UtilityPaymentRequest('TXN-UTL-0001', 'LUKU', '01234567891', 8000),
            Operation::UtilityPaymentLookup => new UtilityLookupRequest('LUKU', '01234567891', 'TXN-LKP-0001'),
            Operation::UtilityPaymentQuery => new UtilityQueryRequest('TXN-UTL-0001'),
            Operation::WalletCashinProcess => new WalletCashinRequest('TXN-CSH-0001', 'CASHIN', '255700000002', 2500),
            Operation::WalletCashinNameLookup => new WalletNameLookupRequest('CASHIN', '255700000002', 'TXN-CSH-0002'),
            Operation::WalletCashinQuery => new WalletCashinQueryRequest('TXN-CSH-0001'),
            Operation::VendorBalance => new VendorBalanceRequest('TXN-BAL-0001'),
        };
    }
}
