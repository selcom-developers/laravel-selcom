<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Loads the copy of the shared spec vectors in resources/vectors (see SPEC_VERSION).
 */
final class Vectors
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $relativePath): array
    {
        $file = __DIR__ . '/../../resources/vectors/' . $relativePath;
        $json = file_get_contents($file);
        Assert::assertIsString($json, $file);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<string> e.g. responses/checkout.order-status.json
     */
    public static function responseFiles(): array
    {
        $files = glob(__DIR__ . '/../../resources/vectors/responses/*.json');
        Assert::assertIsArray($files);
        sort($files);

        return array_map(static fn (string $f): string => 'responses/' . basename($f), $files);
    }

    public static function webhookSecret(): string
    {
        $verification = self::load('responses/checkout.webhook.json')['verification'] ?? null;
        Assert::assertIsArray($verification);
        $secret = $verification['secret'] ?? null;
        Assert::assertIsString($secret);

        return $secret;
    }
}
