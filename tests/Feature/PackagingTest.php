<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Feature;

use PHPUnit\Framework\TestCase;

final class PackagingTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testTheVersionComesFromTheTagNotTheManifest(): void
    {
        self::assertArrayNotHasKey('version', $this->manifest());
    }

    public function testTheFrameworkIsRequiredForTheFoundationPiecesTheCodeUses(): void
    {
        $require = $this->manifest()['require'];
        self::assertIsArray($require);
        // CI narrows this constraint to one Laravel line, so only its presence is checked here.
        self::assertArrayHasKey('laravel/framework', $require);
        self::assertMatchesRegularExpression('/(12|13)/', (string) $require['laravel/framework']);
        self::assertSame('^0.1', $require['selcom-developers/selcom-php']);
    }

    public function testDevelopmentFilesStayOutOfTheArchive(): void
    {
        $attributes = (string) file_get_contents(__DIR__ . '/../../.gitattributes');
        foreach (['/tests', '/.github', '/scripts'] as $path) {
            self::assertStringContainsString($path . ' export-ignore', $attributes);
        }
    }
}
