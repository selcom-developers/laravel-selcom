<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Tests\Support;

use Psr\Clock\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(private readonly \DateTimeImmutable $now)
    {
    }

    public static function at(string $iso): self
    {
        return new self(new \DateTimeImmutable($iso));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
