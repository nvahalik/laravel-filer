<?php

namespace Nvahalik\Filer\AdapterStrategy;

class Factory
{
    /**
     * @todo Allow for multiple drivers.
     *
     * @param $driver
     * @param $backingDisks
     * @param $originalDisks
     * @param $options
     *
     * @return Basic
     */
    public static function make($driver, $backingDisks, $originalDisks, $options)
    {
        return new Basic(
            $backingDisks,
            $originalDisks,
            $options
        );
    }
}
