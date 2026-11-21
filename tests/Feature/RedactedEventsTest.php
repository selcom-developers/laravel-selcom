<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use SelcomDevelopers\LaravelSelcom\Events\OrderStatusConfirmed;
use SelcomDevelopers\LaravelSelcom\Events\OrderSucceeded;
use SelcomDevelopers\LaravelSelcom\Events\WebhookValid;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Models\SelcomOrder;
use SelcomDevelopers\LaravelSelcom\Testing\SentRequest;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Requests;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Request\VendorBalanceRequest;
use SelcomDevelopers\Selcom\Request\WalletCashinRequest;
use SelcomDevelopers\Selcom\Result\OrderStatusResult;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;

/**
 * Events can be serialised onto a queue: what is serialised carries no raw bodies and no
 * unmasked personal data, while the live event still has the originals.
 */
final class RedactedEventsTest extends TestCase
{
    private const RAW = '{"order_id":"ORD-2001","transid":"TXN-WLT-2001","amount":"10,000","phone":"255700000001","buyer_email":"test.buyer@example.com","payment_status":"COMPLETED"}';

    public function testASerialisedWebhookEventCarriesARedactedPayload(): void
    {
        $payload = WebhookPayload::fromRawBody(self::RAW);
        $event = new WebhookValid(WebhookVerdict::Valid, $payload, ['digest' => '[REDACTED]'], '{"phone":"********0001"}');

        $serialised = serialize($event);

        self::assertStringNotContainsString('255700000001', $serialised);
        self::assertStringNotContainsString('test.buyer@example.com', $serialised);
        self::assertStringContainsString('********0001', $serialised);

        $copy = unserialize($serialised);
        self::assertInstanceOf(WebhookValid::class, $copy);
        self::assertSame('ORD-2001', $copy->payload?->orderId);
        self::assertSame(10000, $copy->payload->amountTzs);
        self::assertSame(WebhookVerdict::Valid, $copy->verdict);
        // The live event keeps what it was given.
        self::assertSame('255700000001', $event->payload?->phone);
    }

    public function testASerialisedOrderStatusEventCarriesARedactedResult(): void
    {
        Selcom::fake(['checkout.order-status' => 'completed']);
        $result = Selcom::orderStatus(new \SelcomDevelopers\Selcom\Request\OrderStatusRequest('ORD-1001'));
        self::assertStringContainsString('255700000001', $result->raw, 'the vector body holds a phone number');

        $serialised = serialize(new OrderStatusConfirmed('ORD-1001', $result, 'applied'));

        self::assertStringNotContainsString('255700000001', $serialised);
        $copy = unserialize($serialised);
        self::assertInstanceOf(OrderStatusConfirmed::class, $copy);
        self::assertInstanceOf(OrderStatusResult::class, $copy->result);
        self::assertSame($result->status, $copy->result->status);
        self::assertSame('applied', $copy->outcome);
    }

    public function testASerialisedSuccessEventCarriesARedactedResult(): void
    {
        $this->runMigrationStubs();
        Selcom::fake(['checkout.order-status' => 'completed']);
        $result = Selcom::execute(Requests::for(Operation::CheckoutOrderStatus));
        $order = SelcomOrder::query()->create(['order_id' => 'ORD-1001', 'amount' => 5000]);

        $serialised = serialize(new OrderSucceeded($order, $result));

        self::assertStringNotContainsString('255700000001', $serialised);
    }

    public function testTheFakesRecordedParamsMaskThePin(): void
    {
        $fake = Selcom::fake(['vendor.balance' => 'success', 'walletcashin.process' => 'success']);
        Selcom::vendorBalance(new VendorBalanceRequest('TXN-BAL-0001'));
        Selcom::walletCashin(new WalletCashinRequest('TXN-CSH-0001', 'CASHIN', '255700000002', 2500));

        foreach ($fake->sent() as $sent) {
            self::assertSame('[REDACTED]', $sent->params['pin'] ?? null, $sent->operation->value);
        }
        self::assertSame('TXN-CSH-0001', $fake->sent(Operation::WalletCashinProcess)[0]->param('transid'));
        self::assertSame(2500, $fake->sent(Operation::WalletCashinProcess)[0]->param('amount'));
    }

    public function testASentRequestDoesNotExposeTheRawRequest(): void
    {
        $fake = Selcom::fake(['vendor.balance' => 'success']);
        Selcom::vendorBalance(new VendorBalanceRequest('TXN-BAL-0001'));

        $dumped = print_r($fake->sent()[0], true) . serialize($fake->sent()[0]);
        self::assertStringNotContainsString('"pin":"0000"', $dumped);
        self::assertFalse(property_exists($fake->sent()[0], 'request'));
    }
}
