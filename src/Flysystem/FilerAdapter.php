<?php

namespace Nvahalik\Filer\Flysystem;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Config as FilerConfig;
use Nvahalik\Filer\Contracts\AdapterStrategy;
use Nvahalik\Filer\Contracts\MetadataRepository;
use Nvahalik\Filer\Exceptions\BackingAdapterException;
use Nvahalik\Filer\Metadata;

class FilerAdapter implements FilesystemAdapter
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

    public function getStorageMetadata(): MetadataRepository
    {
        return $this->storageMetadata;
    }

    /**
     * @inheritDoc
     */
    public function write($path, $contents, Config $config, $isStream = false): void
    {
        // Create the initial entry.
        $backingData = $isStream
            ? $this->adapterManager->writeStream($path, $contents, $config)
            : $this->adapterManager->write($path, $contents, $config);

        // Write the data out somewhere.
        if ($backingData) {
            $metadata = Metadata::generate($path, $contents);
            $metadata->setBackingData($backingData);

            // Update the entry to ensure that we've recorded what actually happened with the data.
            $this->getStorageMetadata()->record($metadata);
        }
    }

    /**
     * @inheritDoc
     */
    public function writeStream($path, $contents, Config $config): void
    {
        $this->write($path, $contents, $config, true);
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
            ->setPath($destination)
            ->setBackingData($copyBackingData);

        $this->getStorageMetadata()->record($copyMetadata);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): void
    {
        // Grab a copy of the metadata and save it with the new path information.
        $metadata = $this->pathMetadata($path);

        // Copy the file.
        $this->adapterManager->delete($path, $metadata->backingData);
        $this->getStorageMetadata()->delete($path);
    }

    /**
     * @inheritDoc
     */
    public function setVisibility(string $path, $visibility): void
    {
        $this->getStorageMetadata()->setVisibility($path, $visibility);
    }

    /**
     * @inheritDoc
     */
    public function has($path)
    {
        return $this->getStorageMetadata()->fileExists($path) || $this->hasOriginalDiskFile($path);
    }

    /**
     * @inheritDoc
     *
     * @todo this is a problem. how can you return an array here? Parent returns a string.
     */
    public function read(string $path): string
    {
        // Get the metadata. Where is this file?
        $metadata = $this->pathMetadata($path);

        if ($contents = $this->adapterManager->read($metadata->backingData)) {
            return $contents;
        }
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
        $metadata = $this->getStorageMetadata()->getMetadata($path);

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
    public function readStream($path): array
    {
        // Get the metadata. Where is this file?
        $metadata = $this->pathMetadata($path);

        if ($stream = $this->adapterManager->readStream($metadata->backingData)) {
            return ['type' => 'file', 'path' => $path, 'stream' => $stream];
        }
    }

    /**
     * @inheritDoc
     */
    public function listContents(string $directory = '', bool $deep = false): iterable
    {
        // We don't need to reach out to the storage provider because we have it all cached.
        return $this->getStorageMetadata()->listContents($path, $deep);
    }

    public function getMetadata($path): FileAttributes
    {
        // Grab our metadata and convert it to a FileAttributes.
        $metadata = $this->pathMetadata($path);

        return FileAttributes::fromArray([
            StorageAttributes::ATTRIBUTE_PATH => $metadata->path,
            StorageAttributes::ATTRIBUTE_FILE_SIZE => $metadata->size,
            StorageAttributes::ATTRIBUTE_VISIBILITY => $metadata->visibility,
            StorageAttributes::ATTRIBUTE_LAST_MODIFIED => $metadata->timestamp,
            StorageAttributes::ATTRIBUTE_MIME_TYPE => $metadata->mimetype,
            StorageAttributes::ATTRIBUTE_EXTRA_METADATA => ['__filer_backing_data' => $metadata->backingData->toArray()],
        ]);
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
            $this->getStorageMetadata()->record($metadata);

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
        // We do not support deleting directories.
    }

    public function createDirectory(string $path, Config $config): void
    {
        // This function is intentionally empty. Filer does not support creating directories.
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
        // We don't really need to do anything. The on-disk doesn't have to change.
        $this->getStorageMetadata()->rename($source, $destination);
    }
}
