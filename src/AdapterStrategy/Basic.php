<?php

namespace Nvahalik\Filer\AdapterStrategy;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\UnableToReadFile;
use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Contracts\AdapterStrategy;
use Nvahalik\Filer\Exceptions\BackingAdapterException;
use Throwable;

class Basic extends BaseAdapterStrategy implements AdapterStrategy
{
    public function getWriteAdapters(): array
    {
        return $this->backingAdapters + $this->options['allow_new_files_on_original_disks'] ?
            $this->originalDisks : [];
    }

    public function getReadAdapters(): array
    {
        return array_merge($this->backingAdapters, $this->originalDisks);
    }

    public function getOriginalDiskMetadata($path): array
    {
        $metadata = [];

        foreach ($this->originalDisks as $name => $adapter) {
            try {
                $adapterMetadata = $adapter->getMetadata($path);
                if (isset($adapterMetadata['etag'])) {
                    $adapterMetadata['etag'] = rtrim(ltrim($adapterMetadata['etag'], '"'), '"');
                }
                $metadata[$name] = $adapterMetadata;
            } catch (Throwable) {
                // Ignore any exceptions, at least for now.
            }
        }

        return $metadata;
    }

    public function has($path): bool
    {
        foreach ($this->originalDisks as $adapter) {
            /** @var \Illuminate\Contracts\Filesystem\Filesystem $adapter */
            if ($adapter->exists($path)) {
                return true;
            }
        }

        return false;
    }

    public function writeStream($path, $stream, Config $config): ?BackingData
    {
        /**
         * @var string $diskId
         * @var FilesystemAdapter $backingAdapter
         */
        foreach ($this->backingAdapters as $diskId => $backingAdapter) {
            try {
                $originalConfig = $backingAdapter->getConfig();
                $originalConfig->setFallback($config);

                $backingAdapter->getAdapter()->writeStream($path, $stream, $originalConfig);
                return BackingData::diskAndPath($diskId, $path);
            } catch (Throwable) {
                // Something failed, but not due to an existing file...
            }
        }

        return null;
    }

    public function write($path, $contents, Config $config): ?BackingData
    {
        /**
         * @var string $diskId
         * @var FilesystemAdapter $backingAdapter
         */
        foreach ($this->backingAdapters as $diskId => $backingAdapter) {
            try {
                $originalConfig = $backingAdapter->getConfig();
                $originalConfig->setFallback($config);

                $backingAdapter->getAdapter()->write($path, $contents, $originalConfig);
                return BackingData::diskAndPath($diskId, $path);
            } catch (Throwable) {
                // Something failed, but not due to an existing file...
            }
        }

        return null;
    }

    public function readStream($backingData)
    {
        foreach ($this->getMatchingReadAdapters($backingData) as $id => $adapter) {
            /** @var \Illuminate\Contracts\Filesystem\Filesystem $adapter */
            try {
                if ($object = $adapter->getAdapter()->readStream($this->readAdapterPath($id, $backingData))) {
                    return $object['stream'];
                }
            } catch (Throwable) {
                // We want to allow multiple backing adapters to try it.
            }
        }

        throw new UnableToReadFile;
    }

    /**
     * @param  BackingData  $backingData  An array of backing data.
     * @return resource
     */
    public function read(BackingData $backingData)
    {
        foreach ($this->getMatchingReadAdapters($backingData) as $id => $adapter) {
            try {
                if ($response = $adapter->getAdapter()->read($this->readAdapterPath($id, $backingData))) {
                    return $response['contents'];
                }
            } catch (Throwable $e) {
            }
        }
    }

    private function getMatchingReadAdapters(BackingData $backingData): array
    {
        return array_filter($this->getReadAdapters(), static function ($name) use ($backingData) {
            return in_array($name, $backingData->disks(), true);
        }, ARRAY_FILTER_USE_KEY);
    }

    public function delete(string $path, $backingData)
    {
        foreach ($this->getMatchingReadAdapters($backingData) as $id => $adapter) {
            try {
                return $adapter->getAdapter()->delete($this->readAdapterPath($id, $backingData));
            } catch (Throwable $e) {
                throw new BackingAdapterException("Unable to delete ($path) on disk ($id).");
            }
        }

        return true;
    }

    private function readAdapterPath(string $id, BackingData $backingData)
    {
        return $backingData->getDisk($id)['path'];
    }

    public function copy(BackingData $source, string $destination): ?BackingData
    {
        try {
            $stream = $this->readStream($source);

            return $this->writeStream($destination, $stream);
        } catch (Throwable $e) {
            return null;
        }
    }

    public function getDisk(string $disk)
    {
        $adapters = $this->getReadAdapters();

        if (! array_key_exists($disk, $adapters)) {
            throw new BackingAdapterException(sprintf('The backing adapter (%s) does not exist on the adapter.',
                $disk));
        }

        return $adapters[$disk];
    }
}
