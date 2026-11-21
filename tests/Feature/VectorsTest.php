<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use SelcomDevelopers\LaravelSelcom\Events\WebhookInvalid;
use SelcomDevelopers\LaravelSelcom\Events\WebhookReceived;
use SelcomDevelopers\LaravelSelcom\Events\WebhookReplay;
use SelcomDevelopers\LaravelSelcom\Events\WebhookStale;
use SelcomDevelopers\LaravelSelcom\Events\WebhookUnsigned;
use SelcomDevelopers\LaravelSelcom\Events\WebhookValid;
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Support\DigestSignerFactory;
use SelcomDevelopers\LaravelSelcom\Tests\Support\FixedClock;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Requests;
use SelcomDevelopers\LaravelSelcom\Tests\Support\Vectors;
use SelcomDevelopers\LaravelSelcom\Tests\TestCase;
use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Exception\InvalidResponseException;
use SelcomDevelopers\Selcom\Exception\SigningException;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Signing\RequestSigner;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;
use Psr\Clock\ClockInterface;

/**
 * Runs the shared spec vectors (resources/vectors, tag in SPEC_VERSION) through the package's own
 * wiring: the signing cases and rejects through the container's signer, every response scenario
 * through the real client on the fake transport, and every webhook sample through the route.
 */
final class VectorsTest extends TestCase
{
    public const SIGNING_CASES = 20;
    public const SIGNING_REJECTS = 11;
    public const RESPONSE_SCENARIOS = 120;
    public const WEBHOOK_SAMPLES = 15;

    // ---- Signing ---------------------------------------------------------------------------

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function signingCases(): iterable
    {
        $cases = Vectors::load('signing.json')['cases'];
        self::assertIsArray($cases);
        foreach ($cases as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['id']);
            /** @var array<string, mixed> $case */
            yield $case['id'] => [$case];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function signingRejects(): iterable
    {
        $rejects = Vectors::load('signing.json')['rejects'];
        self::assertIsArray($rejects);
        foreach ($rejects as $reject) {
            self::assertIsArray($reject);
            self::assertIsString($reject['id']);
            /** @var array<string, mixed> $reject */
            yield $reject['id'] => [$reject];
        }
    }

    public function testSigningVectorCounts(): void
    {
        self::assertCount(self::SIGNING_CASES, iterator_to_array(self::signingCases()));
        self::assertCount(self::SIGNING_REJECTS, iterator_to_array(self::signingRejects()));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('signingCases')]
    public function testSigningCase(array $case): void
    {
        $expected = $case['expected'];
        self::assertIsArray($expected);
        self::assertIsString($case['method']);
        self::assertIsArray($case['params']);
        self::assertIsString($case['timestamp']);
        self::assertIsString($case['api_key']);
        self::assertIsString($case['secret']);

        // The digest signer comes from the container's factory: that is the layer under test here.
        $digest = $this->app?->make(DigestSignerFactory::class)(new Credentials('k', $case['secret'], 'V'));
        self::assertNotNull($digest);

        /** @var array<string, mixed> $params */
        $params = $case['params'];
        $signed = RequestSigner::sign($case['method'], $params, $case['timestamp'], $case['api_key'], $digest);

        self::assertSame($expected['flattened'], array_map(static fn (array $p): array => [$p[0], $p[1]], $signed->flattened));
        self::assertSame($expected['signed_fields'], $signed->signedFields);
        self::assertSame($expected['signing_string'], $signed->signingString);
        self::assertSame($expected['digest'], $signed->digest);
        self::assertSame($expected['authorization'], $signed->authorization);
        self::assertSame($expected['digest_method'], $signed->digestMethod);
        if (isset($expected['query'])) {
            self::assertSame($expected['query'], $signed->query);
        }
        if (isset($expected['body'])) {
            self::assertIsString($expected['body']);
            self::assertNotNull($signed->body);
            $want = json_decode($expected['body'], true, 512, JSON_THROW_ON_ERROR);
            $got = json_decode($signed->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($want);
            self::assertIsArray($got);
            self::assertSame(array_keys($want), array_keys($got));
            self::assertSame($want, $got);
        }
    }

    /**
     * @param array<string, mixed> $reject
     */
    #[DataProvider('signingRejects')]
    public function testSigningReject(array $reject): void
    {
        self::assertIsString($reject['method']);
        self::assertIsArray($reject['params']);
        self::assertIsString($reject['timestamp']);
        self::assertIsString($reject['api_key']);
        self::assertIsString($reject['secret']);

        /** @var array<string, mixed> $params */
        $params = $reject['params'];
        try {
            // Credentials refuses an empty secret itself, which is also "fail closed"; hand the
            // factory a signer-level secret directly so the signer's own check is what runs.
            $digest = new \SelcomDevelopers\Selcom\Signing\Hs256DigestSigner($reject['secret']);
            RequestSigner::sign($reject['method'], $params, $reject['timestamp'], $reject['api_key'], $digest);
            self::fail('The signer must refuse ' . (string) $reject['id']);
        } catch (SigningException $e) {
            self::assertSame($reject['error'], $e->errorCode);
        } catch (\SelcomDevelopers\Selcom\Exception\ConfigurationException) {
            // An empty secret is refused when the digest signer is built: nothing is signed either way.
            self::assertSame('EMPTY_SECRET', $reject['error']);
        }
    }

    // ---- Responses -------------------------------------------------------------------------

    /** @return iterable<string, array{string, string, string, string|null}> */
    public static function responseScenarios(): iterable
    {
        foreach (Vectors::responseFiles() as $file) {
            $vector = Vectors::load($file);
            if (!isset($vector['scenarios'])) {
                continue; // the webhook samples have their own test below
            }
            self::assertIsString($vector['operation']);
            self::assertIsArray($vector['scenarios']);
            foreach ($vector['scenarios'] as $scenario) {
                self::assertIsArray($scenario);
                self::assertIsString($scenario['name']);
                $status = $scenario['expected_status'] ?? null;
                $error = $scenario['expected_error'] ?? null;
                self::assertTrue(is_string($status) || is_string($error));
                yield $vector['operation'] . ' / ' . $scenario['name'] => [
                    $vector['operation'],
                    $scenario['name'],
                    is_string($status) ? $status : '',
                    is_string($error) ? $error : null,
                ];
            }
        }
    }

    public function testResponseScenarioCount(): void
    {
        self::assertCount(self::RESPONSE_SCENARIOS, iterator_to_array(self::responseScenarios()));
    }

    #[DataProvider('responseScenarios')]
    public function testResponseScenario(string $operation, string $scenario, string $expectedStatus, ?string $expectedError): void
    {
        $op = Operation::from($operation);
        Selcom::fake([$operation => $scenario]);

        if ($expectedError !== null) {
            self::assertSame('INVALID_RESPONSE', $expectedError);
            $this->expectException(InvalidResponseException::class);
            Selcom::execute(Requests::for($op));

            return;
        }

        $result = Selcom::execute(Requests::for($op));
        self::assertSame($expectedStatus, $result->status->value);
        Selcom::assertSentCount(1);
    }

    // ---- Webhooks --------------------------------------------------------------------------

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('selcom.webhook.enabled', true);
        $app['config']->set('selcom.webhook.path', 'selcom/webhook/test-token');
    }

    public function testWebhookSamplesInOrderThroughOneReplayStore(): void
    {
        $vector = Vectors::load('responses/checkout.webhook.json');
        $verification = $vector['verification'];
        self::assertIsArray($verification);
        self::assertIsString($verification['now']);
        $samples = $vector['samples'];
        self::assertIsArray($samples);
        self::assertCount(self::WEBHOOK_SAMPLES, $samples);

        // The clock is fixed at the vectors' "now"; the replay store is the package's cache-backed one.
        $this->app?->instance(ClockInterface::class, FixedClock::at($verification['now']));

        $events = [];
        $classes = [
            WebhookValid::class, WebhookUnsigned::class, WebhookInvalid::class, WebhookStale::class,
            WebhookReplay::class, WebhookReceived::class,
        ];
        Event::fake(array_diff($classes, [])); // record, do not let listeners run

        foreach ($samples as $sample) {
            self::assertIsArray($sample);
            self::assertIsString($sample['name']);
            self::assertIsString($sample['raw_body']);
            self::assertIsArray($sample['headers']);
            self::assertIsString($sample['expected_verification']);

            $server = [];
            foreach ($sample['headers'] as $name => $value) {
                self::assertIsString($name);
                self::assertIsString($value);
                $key = strtoupper(str_replace('-', '_', $name));
                $server[$key === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_' . $key] = $value;
            }

            $response = $this->call('POST', '/selcom/webhook/test-token', [], [], [], $server, $sample['raw_body']);

            $verdict = WebhookVerdict::from($sample['expected_verification']);
            $response->assertStatus($verdict->httpStatus());
            $events[$sample['name']] = $verdict;
        }

        // Every verdict has its own event, and each delivery fired exactly one of them plus "received".
        $perVerdict = [
            WebhookVerdict::Valid->value => WebhookValid::class,
            WebhookVerdict::Unsigned->value => WebhookUnsigned::class,
            WebhookVerdict::Invalid->value => WebhookInvalid::class,
            WebhookVerdict::Stale->value => WebhookStale::class,
            WebhookVerdict::Replay->value => WebhookReplay::class,
        ];
        foreach ($perVerdict as $verdictName => $class) {
            $expectedCount = count(array_filter($events, static fn (WebhookVerdict $v): bool => $v->value === $verdictName));
            Event::assertDispatchedTimes($class, $expectedCount);
        }
        Event::assertDispatchedTimes(WebhookReceived::class, self::WEBHOOK_SAMPLES);
    }

    public function testWebhookAmountsParseAsInTheVectors(): void
    {
        $vector = Vectors::load('responses/checkout.webhook.json');
        $verification = $vector['verification'];
        self::assertIsArray($verification);
        self::assertIsString($verification['now']);
        $this->app?->instance(ClockInterface::class, FixedClock::at($verification['now']));

        $seen = [];
        Event::listen(WebhookReceived::class, static function (WebhookReceived $e) use (&$seen): void {
            $seen[] = $e->payload?->amountTzs;
        });

        $samples = $vector['samples'];
        self::assertIsArray($samples);
        $expected = [];
        foreach ($samples as $sample) {
            self::assertIsArray($sample);
            self::assertIsArray($sample['headers']);
            self::assertIsString($sample['raw_body']);
            $server = [];
            foreach ($sample['headers'] as $name => $value) {
                self::assertIsString($name);
                self::assertIsString($value);
                $key = strtoupper(str_replace('-', '_', $name));
                $server[$key === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_' . $key] = $value;
            }
            $this->call('POST', '/selcom/webhook/test-token', [], [], [], $server, $sample['raw_body']);
            $expected[] = $sample['expected_amount_tzs'] ?? null;
        }

        self::assertSame($expected, $seen);
    }
}
