<?php

namespace Nvahalik\Filer\Flysystem;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Util;
use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Config as FilerConfig;
use Nvahalik\Filer\Contracts\AdapterStrategy;
use Nvahalik\Filer\Contracts\MetadataRepository;
use Nvahalik\Filer\Exceptions\BackingAdapterException;
use Nvahalik\Filer\Metadata;

class FilerAdapter implements \League\Flysystem\FilesystemAdapter
{
    protected FilerConfig $config;

    protected MetadataRepository $storageMetadata;

    protected AdapterStrategy $adapterManager;

    public function __construct(
        FilerConfig $config,
        MetadataRepository $storageMetadata,
        AdapterStrategy $adapterManager
    ) {
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
        $backingData = $isStream
            ? $this->adapterManager->writeStream($path, $contents, $config)
            : $this->adapterManager->write($path, $contents, $config);

        if ($isStream) {
            Util::rewindStream($contents);
        }

        // Write the data out somewhere.
        if ($backingData) {
            $metadata = Metadata::generate($path, $contents);
            $metadata->setBackingData($backingData);

            // Update the entry to ensure that we've recorded what actually happened with the data.
            $this->storageMetadata->record($metadata);

            return $this->storageMetadata->getMetadata($path);
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
        $metadata = $this->pathMetadata($path);

        // Update it.
        try {
            $backingData = $isStream
                ? $this->adapterManager->updateStream($path, $contents, $config, $metadata->backingData)
                : $this->adapterManager->update($path, $contents, $config, $metadata->backingData);

            if ($isStream) {
                Util::rewindStream($contents);
            }

            $metadata->updateContents($contents)
                ->setBackingData($backingData);

            // Update metadata with new size and timestamp?
            $this->storageMetadata->record($metadata);

            return $metadata->toArray();
        } catch (BackingAdapterException $e) {
            return false;
        }
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
        $originalMetadata = $this->pathMetadata($originalPath);

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
        $metadata = $this->pathMetadata($path);

        try {
            // Copy the file.
            $this->adapterManager->delete($path, $metadata->backingData);
            $this->storageMetadata->delete($path);
        } catch (BackingAdapterException $e) {
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
        $metadata = $this->pathMetadata($path);

        if ($contents = $this->adapterManager->read($metadata->backingData)) {
            return ['type' => 'file', 'path' => $path, 'contents' => $contents];
        }

        return false;
    }

    /**
     * Grab the metadata from the store. If it isn't there, try it from the original disks, if they are set.
     *
     * @param $path
     * @return Metadata
     */
    protected function pathMetadata($path): Metadata
    {
        // Get the metadata. Where is this file?
        $metadata = $this->storageMetadata->getMetadata($path);

        // We didn't find it in the metadata store. Is there an original disk?
        // If so, let's reach out to the disk and see if there is data there.
        if (! $metadata && $this->config->originalDisks) {
            $metadata = $this->migrateFromOriginalDisk($path);
        }

        return $metadata;
    }

    /**
     * @inheritDoc
     */
    public function readStream($path)
    {
        // Get the metadata. Where is this file?
        $metadata = $this->pathMetadata($path);

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
        $metadata = $this->pathMetadata($path);

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

    public function getTemporaryUrl(string $path, $expiration, array $options = [])
    {
        $data = $this->getBackingAdapter($path);
        $adapter = $data['adapter'];

        return $adapter->temporaryUrl($path, $expiration, $options);
    }

    public function getBackingAdapter(string $path)
    {
        $metadata = $this->pathMetadata($path)->backingData->toArray();

        $disk = key($metadata);

        return [
            'disk'     => $disk,
            'adapter'  => $this->adapterManager->getDisk($disk),
            'metadata' => current($metadata),
        ];
    }






    //
    // THESE ARE ALL STUBS
    //
    public function fileExists(string $path): bool
    {
        // TODO: Implement fileExists() method.
    }

    public function directoryExists(string $path): bool
    {
        // TODO: Implement directoryExists() method.
    }

    public function deleteDirectory(string $path): void
    {
        // TODO: Implement deleteDirectory() method.
    }

    public function createDirectory(string $path, Config $config): void
    {
        // TODO: Implement createDirectory() method.
    }

    public function visibility(string $path): FileAttributes
    {
        // TODO: Implement visibility() method.
    }

    public function mimeType(string $path): FileAttributes
    {
        // TODO: Implement mimeType() method.
    }

    public function lastModified(string $path): FileAttributes
    {
        // TODO: Implement lastModified() method.
    }

    public function fileSize(string $path): FileAttributes
    {
        // TODO: Implement fileSize() method.
    }

    public function move(string $source, string $destination, Config $config): void
    {
        // TODO: Implement move() method.
    }
}
