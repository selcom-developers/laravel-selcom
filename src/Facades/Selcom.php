<?php

namespace JasiriLabs\Selcom\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Jasirilabs\Selcom\Skeleton\SkeletonClass
 */
class Selcom extends Facade
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
