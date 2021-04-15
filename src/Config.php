<?php

namespace Nvahalik\Filer;

use Illuminate\Filesystem\FilesystemAdapter;

class Config
{
    // The ID that will be used for this configuration/disk.
    public string $id;

    /** @var FilesystemAdapter[] */
    public array $backingDisks;

    /** @var FilesystemAdapter[] */
    public array $originalDisks;

    // What adapter will be used?
    private $adapterClass;

    public function __construct(
        string $id,
        array $backingDisks,
        $adapterClass,
        $originalDisks = []
    ) {
        if ($originalDisks && ! is_array($originalDisks)) {
            $originalDisks = [$originalDisks];
        }

        $this->id = $id;
        $this->adapterClass = $adapterClass;
        $this->backingDisks = $backingDisks;
        $this->originalDisks = $originalDisks ?? [];
    }
}
