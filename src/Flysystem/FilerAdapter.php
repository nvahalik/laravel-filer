<?php

namespace Nvahalik\Filer\Flysystem;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Config as FilerConfig;
use Nvahalik\Filer\Contracts\AdapterStrategy;
use Nvahalik\Filer\Contracts\MetadataRepository;
use Nvahalik\Filer\Metadata;
use Throwable;

class FilerAdapter implements FilesystemAdapter
{
    public function __construct(
        protected FilerConfig $config,
        protected MetadataRepository $storageMetadata,
        protected AdapterStrategy $adapterManager
    ) {
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

        // Add to the metadata store.
        $this->getStorageMetadata()->record(
            Metadata::generate($path, $contents)
                ->setBackingData($backingData)
        );
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
    public function copy(string $source, string $destination, Config $config): void
    {
        // Grab a copy of the metadata and save it with the new path information.
        if (! ($originalMetadata = $this->pathMetadata($source))) {
            return;
        }

        try {
            // Copy the file.
            $copyBackingData = $this->adapterManager->copy($originalMetadata->backingData, $destination, $config);

            $copyMetadata = (clone $originalMetadata)
                ->setPath($destination)
                ->setBackingData($copyBackingData);

            $this->getStorageMetadata()->record($copyMetadata);
        } catch (Throwable $original) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $original);
        }
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
    public function fileExists($path): bool
    {
        return $this->getStorageMetadata()->fileExists($path) || $this->hasOriginalDiskFile($path);
    }

    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        // Allow the backing adapter to find the file. If the backing adapter throws an exception,
        // convert it to the correct Exception that the FilesystemAdapter throws.
        try {
            return $this->adapterManager->read($this->pathMetadata($path)->backingData);
        } catch (Throwable $original) {
            throw UnableToReadFile::fromLocation($path, 'No backing store returned file content.', $original);
        }
    }

    /**
     * Grab the metadata from the store. If it isn't there, try it from the original disks, if they are set.
     *
     * @param $path
     * @return Metadata
     */
    public function pathMetadata($path): Metadata
    {
        // Get the metadata. Where is this file?
        $metadata = $this->getStorageMetadata()->getMetadata($path);

        // It isn't in the metadata store. Is there an original disk? If so, reach out to the disk and see if there is
        // data there.
        if (! $metadata && $this->config->originalDisks) {
            $metadata = $this->migrateFromOriginalDisk($path);
        }

        // No backing data means that no file was found. Throw an exception.
        if (! $metadata) {
            throw new UnableToRetrieveMetadata($path);
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

        try {
            return $this->adapterManager->readStream($metadata->backingData);
        } catch (Throwable $original) {
            throw UnableToReadFile::fromLocation($path, 'No backing store returned a valid stream.', $original);
        }
    }

    /**
     * @inheritDoc
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        // Delegate to the metadata storage handler.
        return $this->getStorageMetadata()->listContents($path, $deep);
    }

    public function getMetadata($path): FileAttributes
    {
        // Grab metadata and convert it to a FileAttributes.
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

    private function migrateFromOriginalDisk(string $path): Metadata
    {
        // Does the file exist on any of the original disks?
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

        throw new UnableToRetrieveMetadata($path);
    }

    private function hasOriginalDiskFile(string $path): bool
    {
        if ($this->config->originalDisks) {
            return $this->adapterManager->has($path);
        }

        return false;
    }

    public function getTemporaryUrl(string $path, $expiration, array $options = [])
    {
        $data = $this->getBackingAdapter($path);

        return $data['adapter']->temporaryUrl($path, $expiration, $options);
    }

    public function getBackingAdapter(string $path): array
    {
        $metadata = $this->pathMetadata($path)->backingData->toArray();

        $disk = key($metadata);

        return [
            'disk' => $disk,
            'adapter' => $this->adapterManager->getDisk($disk),
            'metadata' => current($metadata),
        ];
    }

    /**
     * @param  string  $path
     * @return bool
     */
    public function directoryExists(string $path): bool
    {
        // Directories are fake. So they always technically exist.
        return true;
    }

    public function deleteDirectory(string $path): void
    {
        // Deleting directories is not supported.
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
        // The on-disk doesn't have to change. Just rename the file in the metadata storage system.
        $this->getStorageMetadata()->rename($source, $destination);
    }
}
