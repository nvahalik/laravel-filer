<?php

namespace Nvahalik\Filer\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class BackingData implements CastsAttributes
{

    public function get($model, string $key, $value, array $attributes)
    {
        return \Nvahalik\Filer\BackingData::unserialize($value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value->toJson();
    }
}
