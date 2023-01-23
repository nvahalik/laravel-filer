<?php

namespace Nvahalik\Filer\MetadataRepository;

use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Contracts\MetadataRepository;
use Nvahalik\Filer\Metadata;

class Memory extends Base implements MetadataRepository
{
    protected $data = [];

    public function getSize($path)
    {
        return $this->valueOrFalse($path, 'size');
    }

    public function setStorageId(string $id): static
    {
        if (! isset($this->data[$id])) {
            $this->data[$id] = [];
        }

        return parent::setStorageId($id);
    }

    public function getData()
    {
        return $this->data;
    }

    public function getMimetype($path)
    {
        return $this->valueOrFalse($path, 'mimetype');
    }

    public function getVisibility($path)
    {
        return $this->valueOrFalse($path, 'visibility');
    }

    public function getTimestamp($path)
    {
        return $this->valueOrFalse($path, 'timestamp');
    }

    /**
     * @param $path
     * @return \Nvahalik\Filer\Metadata|null
     */
    public function getMetadata($path): ?Metadata
    {
        if (! isset($this->data[$this->storageId][$path])) {
            return null;
        }

        $metadata = $this->data[$this->storageId][$path];

        return $this->data[$this->storageId][$path];
    }

    public function listContents($directory = '', $recursive = false)
    {
        $directory = ltrim(rtrim($directory, '/').'/', '/');

        $directoryOffset = strlen($directory);

        $matchingFiles = array_filter(array_keys($this->data[$this->storageId]), function ($path) use (
            $recursive,
            $directory,
            $directoryOffset
        ) {
            $matchesPath = $directory === '' ? true : stripos($path, $directory) === 0;
            $hasTrailingDirectories = strpos($path, '/', $directoryOffset) === false;

            return $matchesPath && ($recursive ? true : $hasTrailingDirectories);
        });

        $contents = [];

        foreach ($matchingFiles as $file) {
            $contents[] = $this->data[$this->storageId] + [
                'path' => str_replace($directory, '', $file),
            ];
        }

        return $contents;
    }

    public function fileExists(string $path): bool
    {
        return isset($this->data[$this->storageId][$path]);
    }

    /**
     * Determine if a directory exists.
     *
     * If we have a file stored at a/b/file.txt, then `a` exists. And `a/b` exists.
     */
    public function directoryExists(string $path): bool
    {
        $breakdown = array_filter(explode(DIRECTORY_SEPARATOR, $path));

        foreach ($this->data as $filePath => $data) {
            // If the strings don't match, then don't worry about it.
            if (! str_starts_with($filePath, $path)) {
                continue;
            }

            // We don't store directories in the index, only files. This is a file.
            if ($filePath === $path) {
                // It is actually a file.
                return false;
            }

            // Break down the path into parts and see if the requested path lives within
            // the path. If it does, then the directory "exists".
            $pathBreakdown = array_filter(explode(DIRECTORY_SEPARATOR, $filePath));
            if (count(array_diff($breakdown, $pathBreakdown)) === 0) {
                return true;
            }
        }

        return false;
    }

    public function setVisibility(string $path, string $visibility)
    {
        if ($this->fileExists($path)) {
            $this->data[$this->storageId][$path]->visibility = $visibility;
        }

        return $this->getMetadata($path);
    }

    public function record(Metadata $metadata)
    {
        $this->data[$this->storageId][$metadata->path] = $metadata;
    }

    public function delete(string $path)
    {
        unset($this->data[$this->storageId][$path]);
    }

    public function rename(string $path, string $newPath)
    {
        $this->data[$this->storageId][$newPath] = $this->data[$this->storageId][$path];
        $this->data[$this->storageId][$path] = null;
        unset($this->data[$this->storageId][$path]);
    }

    public function setBackingData(string $path, BackingData $backingData)
    {
        $this->data[$this->storageId][$path]->setBackingData($backingData);
    }
}
