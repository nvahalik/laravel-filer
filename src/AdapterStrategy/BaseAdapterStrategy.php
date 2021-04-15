<?php

namespace Nvahalik\Filer\AdapterStrategy;

class BaseAdapterStrategy
{
    protected array $backingAdapters;
    protected array $originalDisks;
    protected array $options;

    public function __construct(
        array $backingAdapters,
        array $originalDisks,
        array $options = [
            'allow_new_files_on_original_disks' => false,
        ]
    ) {
        $this->backingAdapters = $backingAdapters;
        $this->originalDisks = $originalDisks;
        $this->options = $options;
    }

    public function setOriginalDisks(array $originalDisks)
    {
        $this->originalDisks = $originalDisks;

        return $this;
    }

    public function setBackingDisks(array $backingDisks)
    {
        $this->backingAdapters = $backingDisks;

        return $this;
    }

    public function setOptions(array $options)
    {
        $this->options = $options;

        return $this;
    }
}
