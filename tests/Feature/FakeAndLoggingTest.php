<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use Monolog\Handler\TestHandler;
use PHPUnit\Framework\AssertionFailedError;
use Illuminate\Support\Facades\Log;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Testing\Scenarios;
use SelcomDevelopers\LaravelSelcom\Testing\SentRequest;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Requests;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Exception\TransportException;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;
use SelcomDevelopers\Selcom\Request\UtilityPaymentRequest;
use SelcomDevelopers\Selcom\Request\WalletCashinRequest;
use SelcomDevelopers\Selcom\Status;

final class FakeAndLoggingTest extends TestCase
{
    public function testFakeRunsTheRealClientOnScenarioPresets(): void
    {
        $fake = Selcom::fake([
            'checkout.order-status' => 'completed',
            'utilitypayment.process' => ['pending', 'success'],
        ]);

        self::assertSame(Status::Success, Selcom::orderStatus(new OrderStatusRequest('ORD-1001'))->status);
        $request = new UtilityPaymentRequest('TXN-UTL-0001', 'LUKU', '01234567891', 8000);
        self::assertSame(Status::Pending, Selcom::utilityPayment($request)->status);
        self::assertSame(Status::Success, Selcom::utilityPayment($request)->status);
        self::assertSame(Status::Success, Selcom::utilityPayment($request)->status, 'the last item repeats');

        $fake->assertSentCount(4);
        $fake->assertSent(Operation::UtilityPaymentProcess, null, 3);
        $fake->assertSent(Operation::CheckoutOrderStatus, static fn (SentRequest $r): bool => $r->param('order_id') === 'ORD-1001');
        $fake->assertNotSent(Operation::WalletCashinProcess);
    }

    public function testFakeRequestsAreSignedAndTheirSecretsMasked(): void
    {
        $fake = Selcom::fake(['walletcashin.process' => 'success']);
        Selcom::walletCashin(new WalletCashinRequest('TXN-CSH-0001', 'CASHIN', '255700000002', 2500));

        $sent = $fake->sent(Operation::WalletCashinProcess)[0];
        self::assertSame('[REDACTED]', $sent->headers['Digest']);
        self::assertSame('[REDACTED]', $sent->headers['Authorization']);
        self::assertSame('TXN-CSH-0001', $sent->param('transid'));
        self::assertNotSame('', $sent->request->headers['Digest'] ?? '');
    }

    public function testTransportFailurePresetsAreClassified(): void
    {
        Selcom::fake([
            'utilitypayment.process' => Scenarios::unknownOutcome(),
            'walletcashin.process' => Scenarios::notSent(),
        ]);

        try {
            Selcom::utilityPayment(new UtilityPaymentRequest('TXN-UTL-0001', 'LUKU', '01234567891', 8000));
            self::fail('expected a transport error');
        } catch (TransportException $e) {
            self::assertFalse($e->isNotSent());
        }
        try {
            Selcom::walletCashin(new WalletCashinRequest('TXN-CSH-0001', 'CASHIN', '255700000002', 2500));
            self::fail('expected a transport error');
        } catch (TransportException $e) {
            self::assertTrue($e->isNotSent());
        }
        // A payment is never repeated by the client, whatever the error.
        Selcom::assertSentCount(2);
    }

    public function testAnOperationWithNoFakeResponseFailsLoudly(): void
    {
        Selcom::fake(['checkout.order-status' => 'completed']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no response for walletcashin.process');
        Selcom::execute(Requests::for(Operation::WalletCashinProcess));
    }

    public function testUnknownScenarioNamesListWhatIsAvailable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('in-progress-111');
        Scenarios::response(Operation::CheckoutOrderStatus, 'nonsense');
    }

    public function testCustomBodiesAndClosuresWork(): void
    {
        Selcom::fake([
            'vendor.balance' => static fn (SentRequest $r): array => ['resultcode' => '000', 'result' => 'SUCCESS', 'reference' => 'REF1', 'data' => [['balance' => '1,000']]],
        ]);

        self::assertTrue(Selcom::execute(Requests::for(Operation::VendorBalance))->isSuccess());
    }

    public function testAssertionsFailWhenTheyShould(): void
    {
        $fake = Selcom::fake(['checkout.order-status' => 'completed']);
        $fake->assertNothingSent();
        Selcom::orderStatus(new OrderStatusRequest('ORD-1001'));

        $this->expectException(AssertionFailedError::class);
        Selcom::assertNotSent(Operation::CheckoutOrderStatus);
    }

    public function testFakeReplacesTheNetworkEvenAfterTheClientWasResolved(): void
    {
        $before = $this->app?->make(Client::class);
        Selcom::fake(['checkout.order-status' => 'pending']);

        self::assertNotSame($before, $this->app?->make(Client::class));
        self::assertSame(Status::Pending, Selcom::orderStatus(new OrderStatusRequest('ORD-1001'))->status);
    }

    public function testLoggingGoesThroughTheChannelAndTheRedactor(): void
    {
        $config = $this->app?->make('config');
        self::assertNotNull($config);
        $config->set('logging.channels.selcom_test', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $config->set('selcom.logging.channel', 'selcom_test');

        Selcom::fake(['walletcashin.process' => 'success']);
        Selcom::walletCashin(new WalletCashinRequest('TXN-CSH-0001', 'CASHIN', '255700000002', 2500));

        $logger = Log::channel('selcom_test');
        $handler = null;
        foreach ($logger->getLogger()->getHandlers() as $h) { // @phpstan-ignore method.notFound
            $handler = $h;
        }
        self::assertInstanceOf(TestHandler::class, $handler);
        self::assertTrue($handler->hasInfoThatContains('selcom.response'));

        $text = json_encode(array_map(static fn ($r): array => $r->toArray(), $handler->getRecords()), JSON_THROW_ON_ERROR);
        foreach (['test-secret-0001', '"0000"', '255700000002', 'pin\":\"0000'] as $leak) {
            self::assertStringNotContainsString($leak, $text, 'the log leaked ' . $leak);
        }
        self::assertStringContainsString('[REDACTED]', $text);
        self::assertStringContainsString('********0002', $text, 'the wallet utilityref is masked');
    }

    public function testAmountsAreWholeNumbersAndNeverTruncated(): void
    {
        $fake = Selcom::fake(['walletcashin.process' => 'success']);

        Selcom::walletCashin(new WalletCashinRequest('TXN-CSH-0001', 'CASHIN', '255700000002', '2500'));
        Selcom::walletCashin(new WalletCashinRequest('TXN-CSH-0002', 'CASHIN', '255700000002', 2500.0));
        $fake->assertSentCount(2);

        try {
            new WalletCashinRequest('TXN-CSH-0003', 'CASHIN', '255700000002', 2500.5);
            self::fail('a fractional amount must be refused');
        } catch (\SelcomDevelopers\Selcom\Exception\ValidationException) {
            $fake->assertSentCount(2);
        }
    }

    public function testNoLoggingWithoutAChannel(): void
    {
        $fake = Selcom::fake(['checkout.order-status' => 'completed']);
        Selcom::orderStatus(new OrderStatusRequest('ORD-1001'));
        $fake->assertSentCount(1);
        self::assertNull(config('selcom.logging.channel'));
    }
}
