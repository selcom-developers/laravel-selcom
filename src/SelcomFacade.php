<?php

namespace JasiriLabs\LaravelSelcom;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Jasirilabs\LaravelSelcom\Skeleton\SkeletonClass
 */
class SelcomFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'laravel-selcom';
    }
}
