<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Testing;

use SelcomDevelopers\Selcom\Exception\TransportException;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Transport\TransportFailure;
use SelcomDevelopers\Selcom\Transport\TransportResponse;

/**
 * Scenario presets for Selcom::fake(), drawn from the response vectors of the shared spec
 * (resources/vectors/responses, refreshed with scripts/refresh-spec.sh).
 *
 * A scenario is named exactly as in the vector file of the operation (for example "success",
 * "in-progress-111", "ambiguous-999", "failure", "not-found-404", "gateway-error-no-json";
 * checkout.order-status also has "completed", "cancelled", "rejected", "empty-data" and more).
 * These short aliases work on every operation that has the matching vector:
 *
 *   pending      => in-progress-111
 *   ambiguous    => ambiguous-999
 *   failed       => failure
 *   not-found    => not-found-404
 *   no-verdict   => gateway-error-no-json
 */
final class Scenarios
{
    private const ALIASES = [
        'pending' => 'in-progress-111',
        'ambiguous' => 'ambiguous-999',
        'failed' => 'failure',
        'not-found' => 'not-found-404',
        'no-verdict' => 'gateway-error-no-json',
    ];

    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $cache = [];

    /**
     * The gateway response for a named scenario of an operation.
     */
    public static function response(Operation|string $operation, string $name): TransportResponse
    {
        $operation = self::operation($operation);
        $scenarios = self::load($operation);
        if (!isset($scenarios[$name]) && isset(self::ALIASES[$name])) {
            $name = self::ALIASES[$name];
        }
        $scenario = $scenarios[$name] ?? null;
        if ($scenario === null) {
            throw new \InvalidArgumentException(sprintf(
                'The operation %s has no scenario "%s". Available: %s.',
                $operation->value,
                $name,
                implode(', ', self::names($operation)),
            ));
        }

        $status = $scenario['http_status'] ?? 200;
        $body = $scenario['raw_body'] ?? null;
        if (!is_string($body)) {
            $body = json_encode($scenario['body'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return new TransportResponse(is_int($status) ? $status : 200, $body, ['Content-Type' => 'application/json']);
    }

    /**
     * @return list<string>
     */
    public static function names(Operation|string $operation): array
    {
        return array_keys(self::load(self::operation($operation)));
    }

    /**
     * A response with a body you write yourself.
     *
     * @param array<string, mixed> $body
     */
    public static function body(array $body, int $httpStatus = 200): TransportResponse
    {
        return new TransportResponse(
            $httpStatus,
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * No response, and the request provably never left (DNS failure, connection refused ...).
     */
    public static function notSent(): TransportException
    {
        return new TransportException(TransportFailure::NotSent, 'Fake: the request never reached the gateway.');
    }

    /**
     * No response, and the request may have been processed (a read timeout, a reset ...).
     * For payments treat this exactly like AMBIGUOUS: query, never resend.
     */
    public static function unknownOutcome(): TransportException
    {
        return new TransportException(TransportFailure::UnknownOutcome, 'Fake: no response arrived; the gateway may have acted.');
    }

    private static function operation(Operation|string $operation): Operation
    {
        if ($operation instanceof Operation) {
            return $operation;
        }

        return Operation::tryFrom($operation) ?? throw new \InvalidArgumentException(
            'Unknown operation "' . $operation . '". Use a value such as "checkout.order-status".',
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function load(Operation $operation): array
    {
        if (isset(self::$cache[$operation->value])) {
            return self::$cache[$operation->value];
        }
        $file = dirname(__DIR__, 2) . '/resources/vectors/responses/' . $operation->value . '.json';
        $json = is_file($file) ? file_get_contents($file) : false;
        if ($json === false) {
            throw new \RuntimeException('Scenario file missing for ' . $operation->value . ': ' . $file);
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $list = is_array($decoded) && is_array($decoded['scenarios'] ?? null) ? $decoded['scenarios'] : [];
        $byName = [];
        foreach ($list as $scenario) {
            if (is_array($scenario) && is_string($scenario['name'] ?? null)) {
                /** @var array<string, mixed> $scenario */
                $byName[$scenario['name']] = $scenario;
            }
        }

        return self::$cache[$operation->value] = $byName;
    }
}
