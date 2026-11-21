<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Facades;

use Illuminate\Support\Facades\Facade;
use SelcomDevelopers\LaravelSelcom\Testing\SelcomFake;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Operation;

/**
 * The Selcom client, as a facade. Every method is the selcom-php client's, so the README of
 * selcom-php applies as is.
 *
 * @method static \SelcomDevelopers\Selcom\Result\CreateOrderResult createOrder(\SelcomDevelopers\Selcom\Request\CreateOrderRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\CreateOrderResult createOrderMinimal(\SelcomDevelopers\Selcom\Request\CreateOrderMinimalRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\Result walletPayment(\SelcomDevelopers\Selcom\Request\WalletPaymentRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\Result cardPayment(\SelcomDevelopers\Selcom\Request\CardPaymentRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\OrderStatusResult orderStatus(\SelcomDevelopers\Selcom\Request\OrderStatusRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\Result cancelOrder(\SelcomDevelopers\Selcom\Request\CancelOrderRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\ListOrdersResult listOrders(\SelcomDevelopers\Selcom\Request\ListOrdersRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\StoredCardsResult storedCards(\SelcomDevelopers\Selcom\Request\StoredCardsRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\Result deleteCard(\SelcomDevelopers\Selcom\Request\DeleteCardRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\PayoutResult utilityPayment(\SelcomDevelopers\Selcom\Request\UtilityPaymentRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\LookupResult utilityLookup(\SelcomDevelopers\Selcom\Request\UtilityLookupRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\PayoutResult utilityQuery(\SelcomDevelopers\Selcom\Request\UtilityQueryRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\PayoutResult walletCashin(\SelcomDevelopers\Selcom\Request\WalletCashinRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\LookupResult walletNameLookup(\SelcomDevelopers\Selcom\Request\WalletNameLookupRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\PayoutResult walletCashinQuery(\SelcomDevelopers\Selcom\Request\WalletCashinQueryRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\BalanceResult vendorBalance(\SelcomDevelopers\Selcom\Request\VendorBalanceRequest $request)
 * @method static \SelcomDevelopers\Selcom\Result\Result execute(\SelcomDevelopers\Selcom\Request\OperationRequest $request)
 *
 * @see Client
 */
final class Selcom extends Facade
{
    private static ?SelcomFake $fake = null;

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    /**
     * Replace the network with a fake and return its handle. The real client keeps running (signing,
     * classification), so your tests cover the real code path. See Testing\Scenarios for the presets.
     *
     * @param array<string, mixed> $responses operation => scenario name | sequence | TransportResponse | TransportException | Closure
     */
    public static function fake(array $responses = []): SelcomFake
    {
        $app = static::getFacadeApplication();
        if ($app === null) {
            throw new \RuntimeException('A Laravel application is needed to fake Selcom.');
        }

        return self::$fake = SelcomFake::install($app, $responses);
    }

    /**
     * @param (\Closure(\SelcomDevelopers\LaravelSelcom\Testing\SentRequest): bool)|null $callback
     */
    public static function assertSent(Operation|string $operation, ?\Closure $callback = null, ?int $times = null): void
    {
        self::faked()->assertSent($operation, $callback, $times);
    }

    /**
     * @param (\Closure(\SelcomDevelopers\LaravelSelcom\Testing\SentRequest): bool)|null $callback
     */
    public static function assertNotSent(Operation|string $operation, ?\Closure $callback = null): void
    {
        self::faked()->assertNotSent($operation, $callback);
    }

    public static function assertNothingSent(): void
    {
        self::faked()->assertNothingSent();
    }

    public static function assertSentCount(int $count): void
    {
        self::faked()->assertSentCount($count);
    }

    private static function faked(): SelcomFake
    {
        return self::$fake ?? throw new \LogicException('Call Selcom::fake() first.');
    }
}
