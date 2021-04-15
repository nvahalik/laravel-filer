<?php

namespace Nvahalik\Filer\AdapterStrategy;

use Illuminate\Contracts\Filesystem\FileExistsException;
use Illuminate\Filesystem\FilesystemAdapter;
use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Contracts\AdapterStrategy;

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

    public function getOriginalDiskMetadata($path, $backingData = null)
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

    public function writeStream($path, $stream): ?BackingData
    {
        /**
         * @var string $diskId
         * @var FilesystemAdapter $backingAdapter
         */
        foreach ($this->backingAdapters as $diskId => $backingAdapter) {
            try {
                if ($backingAdapter->writeStream($path, $stream)) {
                    return BackingData::diskAndPath($diskId, $path);
                }
            } catch (FileExistsException $e) {
                // Ignore. We'll try the next one.
            }
        }

        return null;
    }

    public function write($path, $contents): ?BackingData
    {
        /**
         * @var string $diskId
         * @var FilesystemAdapter $backingAdapter
         */
        foreach ($this->backingAdapters as $diskId => $backingAdapter) {
            try {
                if ($backingAdapter->write($path, $contents)) {
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
                return $adapter->readStream($this->readAdapterPath($id, $backingData));
            } catch (\Exception $e) {
            }
        }

        return false;
    }

    /**
     * @param string $path
     * @param BackingData $backingData
     *  An array of backing data.
     *
     * @return false | string
     */
    public function read(BackingData $backingData)
    {
        foreach ($this->getMatchingReadAdapters($backingData) as $id => $adapter) {
            try {
                return $adapter->read($this->readAdapterPath($id, $backingData));
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
                return $adapter->delete($this->readAdapterPath($id, $backingData));
            } catch (\Exception $e) {
                return false;
            }
        }

        return true;
    }

    private function readAdapterPath(string $id, BackingData $backingData)
    {
        return $backingData->getDisk($id)['path'];
    }

    public function update($path, $contents, $backingData): BackingData
    {
        // TODO: Implement update() method.
    }

    public function updateStream($path, $stream, $backingData): BackingData
    {
        // TODO: Implement updateStream() method.
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
}
