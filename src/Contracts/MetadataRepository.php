<?php

namespace Nvahalik\Filer\Contracts;

use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Metadata;

interface MetadataRepository
{
    public function setStorageId(string $id): MetadataRepository;

    /**
     * @return string | false
     */
    public function getVisibility(string $path);

    /**
     * @return int | false
     */
    public function getTimestamp(string $path);

    /**
     * @return string | false
     */
    public function getMimetype(string $path);

    /**
     * @return int | false
     */
    public function getSize(string $path);

    public function getMetadata(string $path): ?Metadata;

    public function listContents(string $directory = '', bool $recursive = false);

    public function fileExists(string $path): bool;

    public function directoryExists(string $path): bool;

    public function setVisibility(string $path, string $visbility);

    public function record(Metadata $metadata);

    public function delete(string $path);

    public function setBackingData(string $path, BackingData $backingData);

    public function rename(string $oldPath, string $newPath);
}
