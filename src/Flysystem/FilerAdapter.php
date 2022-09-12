<?php

namespace Nvahalik\Filer\Flysystem;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
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
                ? $this->adapterManager->writeStream($path, $contents, $config, $metadata->backingData)
                : $this->adapterManager->write($path, $contents, $config, $metadata->backingData);

            if ($isStream) {
                // @todo WDF do I do here?
                //Util::rewindStream($contents);
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
    public function rename($originalPath, $newPath)
    {
        // We don't really need to do anything. The on-disk doesn't have to change.
        $this->storageMetadata->move($originalPath, $newPath);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function copy(string $originalPath, string $newPath, $config = null): void
    {
        // Grab a copy of the metadata and save it with the new path information.
        $originalMetadata = $this->pathMetadata($originalPath);

        if (! $originalMetadata) {
            return;
        }

        try {
            // Copy the file.
            $copyBackingData = $this->adapterManager->copy($originalMetadata->backingData, $newPath);

            if (! $copyBackingData) {
                return;
            }

            $copyMetadata = (clone $originalMetadata)
                ->setPath($newPath)
                ->setBackingData($copyBackingData);

            $this->storageMetadata->record($copyMetadata);
        } catch (\Exception $e) {
            return;
        }

    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): void
    {
        // Grab a copy of the metadata and save it with the new path information.
        $metadata = $this->pathMetadata($path);

        try {
            // Copy the file.
            $this->adapterManager->delete($path, $metadata->backingData);
            $this->storageMetadata->delete($path);
        } catch (BackingAdapterException $e) {
            throw $e;
        }

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
    public function setVisibility(string $path, $visibility): void
    {
        $this->storageMetadata->setVisibility($path, $visibility);
    }

    /**
     * @inheritDoc
     */
    public function has($path)
    {
        return $this->storageMetadata->fileExists($path) || $this->hasOriginalDiskFile($path);
    }

    /**
     * @inheritDoc
     *
     * @todo this is a problem. how can you return an array here? Parent returns a string.
     */
    public function read(string $path)
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
    public function listContents(string $directory = '', bool $deep = false): iterable
    {
        // We don't need to reach out to the storage provider because we have it all cached.
        return $this->storageMetadata->listContents($directory, $deep);
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

    /**
     * @todo Is this right?
     *
     * @param  string  $path
     * @return bool
     */
    public function fileExists(string $path): bool
    {
        return $this->has($path);
    }

    /**
     * @todo Is this right?
     *
     * @param  string  $path
     * @return bool
     */
    public function directoryExists(string $path): bool
    {
        return $this->has($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->deleteDir($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->createDir($path, $config);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->rename($source, $destination);
    }
}
