<?php

namespace Nvahalik\Filer\MetadataRepository;

use Illuminate\Support\Facades\Storage;
use Nvahalik\Filer\Metadata;

class Json extends Memory
{
    private string $filename;

    public function __construct($filename = 'filer-adapter-data.json')
    {
        $this->filename = $filename;

        if (! Storage::has($filename)) {
            Storage::put($filename, '[]');
        }

        $data = Storage::get($filename);

        try {
            $this->data = json_decode($data, true, 10, JSON_THROW_ON_ERROR);
            foreach ($this->data as $storageId => $contents) {
                $this->data[$storageId] = array_map(function ($array) {
                    return Metadata::deserialize($array);
                }, $contents);
            }
        } catch (\JsonException $e) {
            throw $e;
        }
    }

    public function setVisibility(string $path, string $visibility)
    {
        $update = parent::setVisibility($path, $visibility);

        $this->persist();

        return $update;
    }

    public function record(Metadata $metadata)
    {
        parent::record($metadata);

        $this->persist();
    }

    public function delete(string $path)
    {
        parent::delete($path);

        $this->persist();
    }

    public function setBackingData(string $path, $backingData)
    {
        parent::setBackingData($path, $backingData);

        $this->persist();
    }

    public function rename(string $path, string $newPath)
    {
        parent::rename($path, $newPath);

        $this->persist();
    }

    private function persist()
    {
        Storage::put($this->filename, json_encode($this->toArray(), JSON_PRETTY_PRINT | 0, 10));
    }

    public function toArray()
    {
        return array_map(function ($entries) {
            return array_map(function (Metadata $entry) {
                return $entry->serialize();
            }, $entries);
        }, $this->data);
    }
}
