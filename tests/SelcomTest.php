<?php

namespace JasiriLabs\LaravelSelcom\Tests;

use JasiriLabs\LaravelSelcom\Selcom;
use PHPUnit\Framework\TestCase;

class SelcomTest extends TestCase
{
    /**
     * @test
     */

    public function it_can_be_instantiated()
    {
        $laravelSelcom = new Selcom(
            'test-api-key',
        );
        $this->assertInstanceOf(Selcom::class, $laravelSelcom);
    }


}
