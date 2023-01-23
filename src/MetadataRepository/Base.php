<?php

namespace Nvahalik\Filer\MetadataRepository;

class Base
{
    protected string $storageId;

    public function setStorageId(string $id): static
    {
        $this->storageId = $id;

        return $this;
    }

    protected function valueOrFalse($path, $value)
    {
        if ($metadata = $this->getMetadata($path)) {
            return $metadata->{$value};
        }

        return false;
    }
}
