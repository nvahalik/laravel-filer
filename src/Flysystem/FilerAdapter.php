<?php

namespace Nvahalik\Filer\Flysystem;

use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Config as FilerConfig;
use Nvahalik\Filer\Contracts\AdapterStrategy;
use Nvahalik\Filer\Contracts\MetadataRepository;
use Nvahalik\Filer\Metadata;

class FilerAdapter implements AdapterInterface, CanOverwriteFiles
{

    protected FilerConfig $config;

    protected MetadataRepository $storageMetadata;

    protected AdapterStrategy $adapterManager;

    public function __construct(
        FilerConfig $config,
        MetadataRepository $storageMetadata,
        AdapterStrategy $adapterManager
    )
    {
        $this->config = $config;
        $this->storageMetadata = $storageMetadata;
        $this->adapterManager = $adapterManager;
    }

    public function getStorageMetadata()
    {
        return $this->storageMetadata;
    }

    /**
     * @inheritDoc
     */
    public function write($path, $contents, Config $config, $isStream = false)
    {
        // Create the initial entry.
        $this->storageMetadata->record(Metadata::generate($path, $contents));

        $backingData = $isStream
            ? $this->adapterManager->writeStream($path, $contents)
            : $this->adapterManager->write($path, $contents);

        // Write the data out somewhere.
        if ($backingData) {
            // Update the entry to ensure that we've recorded what actually happened with the data.
            $this->storageMetadata->setBackingData($path, $backingData);

            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->write($path, $resource, $config, true);
    }

    /**
     * @inheritDoc
     */
    public function update($path, $contents, Config $config, $isStream = false)
    {
        // Figure out where the data actually is...
        $metadata = $this->getMetadata($path);

        // Update it.
        $backingData = $isStream
            ? $this->adapterManager->updateStream($path, $contents, $metadata->backingData)
            : $this->adapterManager->update($path, $contents, $metadata->backingData);

        $metadata->updateContents($contents)
            ->setBackingData($backingData);

        // Update metadata with new size and timestamp?
        $this->storageMetadata->record($metadata);
    }

    /**
     * @inheritDoc
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->update($path, $resource, $config, true);
    }

    /**
     * @inheritDoc
     */
    public function rename($originalPath, $newPath)
    {
        // We don't really need to do anything. The on-disk doesn't have to change.
        $this->storageMetadata->rename($originalPath, $newPath);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function copy($originalPath, $newPath)
    {
        // Grab a copy of the metadata and save it with the new path information.
        $originalMetadata = $this->getStorageMetadata()->getMetadata($originalPath);

        if (! $originalMetadata) {
            return false;
        }

        try {
            // Copy the file.
            $copyBackingData = $this->adapterManager->copy($originalMetadata->backingData, $newPath);

            if (! $copyBackingData) {
                return false;
            }

            $copyMetadata = (clone $originalMetadata)
                ->setPath($newPath)
                ->setBackingData($copyBackingData);

            $this->storageMetadata->record($copyMetadata);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete($path)
    {
        // Grab a copy of the metadata and save it with the new path information.
        $metadata = $this->getStorageMetadata()->getMetadata($path);

        try {
            // Copy the file.
            $this->adapterManager->delete($path, $metadata->backingData);
            $this->storageMetadata->delete($path);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteDir($dirname)
    {
        // Figure out what files are under the directory, if any, and then delete them.
        // This is gonna be tough.

        return true;
    }

    /**
     * @inheritDoc
     */
    public function createDir($dirname, Config $config)
    {
        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * @inheritDoc
     */
    public function setVisibility($path, $visibility)
    {
        return $this->storageMetadata->setVisibility($path, $visibility);
    }

    /**
     * @inheritDoc
     */
    public function has($path)
    {
        return $this->storageMetadata->has($path) || $this->hasOriginalDiskFile($path);
    }

    /**
     * @inheritDoc
     */
    public function read($path)
    {
        // Get the metadata. Where is this file?
        $metadata = $this->storageMetadata->getMetadata($path);

        // We didn't find it in the metadata store. Is there an original disk?
        // If so, let's reach out to the disk and see if there is data there.
        if (! $metadata && $this->config->originalDisks) {
            $metadata = $this->migrateFromOriginalDisk($path);
        }

        if ($contents = $this->adapterManager->read($metadata->backingData)) {
            return ['type' => 'file', 'path' => $path, 'contents' => $contents];
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function readStream($path)
    {
        // Get the metadata. Where is this file?
        $metadata = $this->storageMetadata->getMetadata($path);

        // We didn't find it in the metadata store. Is there an original disk?
        // If so, let's reach out to the disk and see if there is data there.
        if (! $metadata && $this->config->originalDisks) {
            $metadata = $this->migrateFromOriginalDisk($path);
        }

        if ($stream = $this->adapterManager->readStream($metadata->backingData)) {
            return ['type' => 'file', 'path' => $path, 'stream' => $stream];
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function listContents($directory = '', $recursive = false)
    {
        // We don't need to reach out to the storage provider because we have it all cached.
        return $this->storageMetadata->listContents($directory, $recursive);
    }

    /**
     * @inheritDoc
     */
    public function getMetadata($path, $asObject = false)
    {
        // Convert our metadata to an array.
        $metadata = $this->storageMetadata->getMetadata($path);

        if ($metadata) {
            return $asObject ? $metadata : $metadata->toArray();
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @inheritDoc
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @inheritDoc
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @inheritDoc
     */
    public function getVisibility($path)
    {
        return $this->getMetadata($path);
    }

    private function migrateFromOriginalDisk(string $path): ?Metadata
    {
        // Did we find it?
        $originalMetadata = $this->adapterManager->getOriginalDiskMetadata($path);

        if (count($originalMetadata) > 0) {
            $backingData = new BackingData();

            foreach ($originalMetadata as $disk => $data) {
                $backingData->addDisk($disk, ['path' => $path]);
            }

            $metadata = Metadata::import(current($originalMetadata));
            $metadata->setBackingData($backingData);
            $this->storageMetadata->record($metadata);

            return $metadata;
        }

        return null;
    }

    private function hasOriginalDiskFile(string $path)
    {
        if ($this->config->originalDisks) {
            return $this->adapterManager->has($path);
        }

        return false;
    }
}
