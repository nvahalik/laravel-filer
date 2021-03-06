<?php

namespace Nvahalik\Filer\AdapterStrategy;

use Illuminate\Contracts\Filesystem\FileExistsException;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Config;
use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Contracts\AdapterStrategy;
use Nvahalik\Filer\Exceptions\BackingAdapterException;

class Basic extends BaseAdapterStrategy implements AdapterStrategy
{
    public function getWriteAdapters(): array
    {
        return $this->backingAdapters + $this->config['allow_new_files_on_original_disks'] ?
            $this->originalDisks : [];
    }

    public function getReadAdapters(): array
    {
        return array_merge($this->backingAdapters, $this->originalDisks);
    }

    public function getOriginalDiskMetadata($path)
    {
        $metadata = [];

        foreach ($this->originalDisks as $name => $adapter) {
            try {
                $adapterMetadata = $adapter->getMetadata($path);
                if (isset($adapterMetadata['etag'])) {
                    $adapterMetadata['etag'] = rtrim(ltrim($adapterMetadata['etag'], '"'), '"');
                }
                $metadata[$name] = $adapterMetadata;
            } catch (\Exception $exception) {
                // Ignore any exceptions, at least for now.
            }
        }

        return $metadata;
    }

    public function has($path)
    {
        foreach ($this->originalDisks as $name => $adapter) {
            if ($adapter->has($path)) {
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

                if ($backingAdapter->getAdapter()->writeStream($path, $stream, $originalConfig)) {
                    return BackingData::diskAndPath($diskId, $path);
                }
            } catch (FileExistsException $e) {
                // Ignore. We'll try the next one.
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

                if ($backingAdapter->getAdapter()->write($path, $contents, $originalConfig)) {
                    return BackingData::diskAndPath($diskId, $path);
                }
            } catch (FileExistsException $e) {
                // Ignore. We'll try the next one.
            }
        }

        return null;
    }

    public function readStream($backingData)
    {
        foreach ($this->getMatchingReadAdapters($backingData) as $id => $adapter) {
            try {
                if ($object = $adapter->getAdapter()->readStream($this->readAdapterPath($id, $backingData))) {
                    return $object['stream'];
                }
            } catch (\Exception $e) {
            }
        }

        return false;
    }

    /**
     * @param  BackingData  $backingData  An array of backing data.
     * @return false | string
     */
    public function read(BackingData $backingData)
    {
        foreach ($this->getMatchingReadAdapters($backingData) as $id => $adapter) {
            try {
                if ($response = $adapter->getAdapter()->read($this->readAdapterPath($id, $backingData))) {
                    return $response['contents'];
                }
            } catch (\Exception $e) {
            }
        }

        return false;
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
            } catch (\Exception $e) {
                throw new BackingAdapterException("Unable to delete ($path) on disk ($id).");
            }
        }

        return true;
    }

    private function readAdapterPath(string $id, BackingData $backingData)
    {
        return $backingData->getDisk($id)['path'];
    }

    public function update($path, $contents, Config $config, $backingData): BackingData
    {
        foreach ($this->getMatchingReadAdapters($backingData) as $id => $adapter) {
            try {
                $originalConfig = $adapter->getConfig();
                $originalConfig->setFallback($config);

                $adapter->getAdapter()->update($this->readAdapterPath($id, $backingData), $contents, $originalConfig);
            } catch (\Exception $e) {
                throw new BackingAdapterException('Unable to write to remote adapter: '.$id.' path ('.$path.')');
            }
        }

        return $backingData;
    }

    public function updateStream($path, $stream, Config $config, $backingData): BackingData
    {
        foreach ($this->getMatchingReadAdapters($backingData) as $id => $adapter) {
            try {
                $originalConfig = $adapter->getConfig();
                $originalConfig->setFallback($config);

                $adapter->getAdapter()->updateStream($this->readAdapterPath($id, $backingData), $stream, $originalConfig);
            } catch (\Exception $e) {
                throw new BackingAdapterException('Unable to write to remote adapter: '.$id.' path ('.$path.')');
            }
        }

        return $backingData;
    }

    public function copy(BackingData $source, string $destination): ?BackingData
    {
        try {
            $stream = $this->readStream($source);

            return $this->writeStream($destination, $stream);
        } catch (\Exception $e) {
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
