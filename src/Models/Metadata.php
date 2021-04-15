<?php

namespace Nvahalik\Filer\Models;

use Illuminate\Database\Eloquent\Model;
use Nvahalik\Filer\Casts\BackingData;
use Nvahalik\Filer\Traits\Uuid;

class Metadata extends Model
{
    use Uuid;

    protected $table = 'filer_metadata';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'uuid';

    protected $fillable = [
        'id',
        'disk',
        'path',
        'size',
        'mimetype',
        'etag',
        'visibility',
        'backing_data',
        'timestamp',
    ];

    protected $casts = [
        'size' => 'integer',
        'backing_data' => BackingData::class
    ];

    protected $dates = [
        'timestamp',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setConnection(config('filer.database.connection'));
    }

}
