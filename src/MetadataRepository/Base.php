<?php


namespace Nvahalik\Filer\MetadataRepository;


use Nvahalik\Filer\Contracts\MetadataRepository;

class Base
{

    protected string $storageId;

    public function setStorageId(string $id): MetadataRepository
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
