<?php

namespace Nvahalik\Filer\Facades;

class Filer extends \Illuminate\Support\Facades\Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'laravel-filer';
    }
}

