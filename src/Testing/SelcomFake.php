<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Transport\Transport;

/**
 * The handle returned by Selcom::fake(). The real Client runs against a FakeTransport, so signing,
 * result classification and your own code are all exercised; only the network is replaced.
 *
 *   $fake = Selcom::fake([
 *       'checkout.order-status' => 'completed',
 *       'walletcashin.process' => ['pending', 'success'],   // a sequence; the last repeats
 *       'utilitypayment.process' => Scenarios::unknownOutcome(),
 *   ]);
 *   ...
 *   $fake->assertSent(Operation::CheckoutOrderStatus, fn (SentRequest $r) => $r->param('order_id') === 'ORD-1001');
 *   $fake->assertNotSent(Operation::WalletCashinProcess);
 */
final class SelcomFake
{
    private function __construct(public readonly FakeTransport $transport)
    {
    }

    /**
     * @param array<string, mixed> $responses see FakeTransport::__construct()
     */
    public static function install(Application $app, array $responses = []): self
    {
        $transport = new FakeTransport($responses);
        $app->instance(Transport::class, $transport);
        // Rebuild the client on the fake transport, and drop any facade-cached client.
        $app->forgetInstance(Client::class);
        Facade::clearResolvedInstance(Client::class);

        return new self($transport);
    }

    public function respond(Operation|string $operation, mixed $plan): self
    {
        $this->transport->respond($operation, $plan);

        return $this;
    }

    /**
     * @return list<SentRequest>
     */
    public function sent(Operation|string|null $operation = null): array
    {
        return $this->transport->sent(self::op($operation));
    }

    /**
     * @param (\Closure(SentRequest): bool)|null $callback
     */
    public function assertSent(Operation|string $operation, ?\Closure $callback = null, ?int $times = null): void
    {
        $op = self::op($operation);
        $matching = $this->matching($op, $callback);
        if ($times === null) {
            Assert::assertNotEmpty($matching, 'No matching ' . $op?->value . ' request was sent.');
        } else {
            Assert::assertCount($times, $matching, 'Expected ' . $times . ' matching ' . $op?->value . ' request(s), got ' . count($matching) . '.');
        }
    }

    /**
     * @param (\Closure(SentRequest): bool)|null $callback
     */
    public function assertNotSent(Operation|string $operation, ?\Closure $callback = null): void
    {
        $op = self::op($operation);
        Assert::assertEmpty($this->matching($op, $callback), 'An unexpected ' . $op?->value . ' request was sent.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->transport->sent(), 'Expected no Selcom request, but ' . count($this->transport->sent()) . ' were sent.');
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->transport->sent(), 'Expected ' . $count . ' Selcom request(s).');
    }

    /**
     * @param (\Closure(SentRequest): bool)|null $callback
     *
     * @return list<SentRequest>
     */
    private function matching(?Operation $operation, ?\Closure $callback): array
    {
        return array_values(array_filter(
            $this->transport->sent($operation),
            static fn (SentRequest $s): bool => $callback === null || $callback($s) === true,
        ));
    }

    private static function op(Operation|string|null $operation): ?Operation
    {
        if ($operation === null || $operation instanceof Operation) {
            return $operation;
        }

        return Operation::tryFrom($operation) ?? throw new \InvalidArgumentException('Unknown operation "' . $operation . '".');
    }
}
