<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Testing;

use SelcomDevelopers\Selcom\Exception\TransportException;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Transport\Transport;
use SelcomDevelopers\Selcom\Transport\TransportRequest;
use SelcomDevelopers\Selcom\Transport\TransportResponse;

/**
 * A Transport that never touches the network. Responses are set per operation; a call to an
 * operation with nothing set fails loudly, so code under test cannot reach the gateway by accident.
 */
final class FakeTransport implements Transport
{
    /** @var list<SentRequest> */
    private array $sent = [];

    /** @var array<string, list<mixed>> */
    private array $plans = [];

    /** @var array<string, int> */
    private array $cursor = [];

    /**
     * @param array<string, mixed> $responses operation value => scenario name | TransportResponse | TransportException | Closure | list of those
     */
    public function __construct(array $responses = [])
    {
        foreach ($responses as $operation => $plan) {
            $this->respond($operation, $plan);
        }
    }

    /**
     * Set the response(s) for an operation. A list is a sequence: the first call gets the first
     * item and so on; the last item repeats.
     */
    public function respond(Operation|string $operation, mixed $plan): self
    {
        $key = $operation instanceof Operation ? $operation->value : $operation;
        Scenarios::names($key); // fails early on an unknown operation

        $this->plans[$key] = is_array($plan) && array_is_list($plan) && $plan !== [] ? $plan : [$plan];
        $this->cursor[$key] = 0;

        return $this;
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $sent = SentRequest::from($request);
        $this->sent[] = $sent;

        $key = $sent->operation->value;
        $plan = $this->plans[$key] ?? null;
        if ($plan === null) {
            throw new \LogicException(
                'Selcom::fake() has no response for ' . $key . ". Pass one, for example Selcom::fake(['" . $key . "' => 'success']).",
            );
        }
        $index = min($this->cursor[$key], count($plan) - 1);
        ++$this->cursor[$key];

        return $this->resolve($plan[$index], $sent);
    }

    /**
     * @return list<SentRequest>
     */
    public function sent(?Operation $operation = null): array
    {
        return $operation === null
            ? $this->sent
            : array_values(array_filter($this->sent, static fn (SentRequest $s): bool => $s->operation === $operation));
    }

    private function resolve(mixed $item, SentRequest $sent): TransportResponse
    {
        if ($item instanceof \Closure) {
            $item = $item($sent);
        }
        if ($item instanceof TransportException) {
            throw $item;
        }
        if ($item instanceof TransportResponse) {
            return $item;
        }
        if (is_string($item)) {
            return Scenarios::response($sent->operation, $item);
        }
        if (is_array($item)) {
            /** @var array<string, mixed> $item */
            return Scenarios::body($item);
        }

        throw new \LogicException('A fake response must be a scenario name, a TransportResponse, a TransportException, an array body or a Closure returning one of them.');
    }
}
