<?php


namespace Nvahalik\Filer;


use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

class BackingData implements Arrayable, Jsonable
{
    protected array $data = [];

    public function addDisk($disk, $data)
    {
        $this->data[$disk] = $data;

        return $this;
    }

    public function fill($diskData)
    {
        $this->data = $diskData;

        return $this;
    }

    public function getDisk($disk)
    {
        return $this->data[$disk] ?? null;
    }

    public function removeDisk($disk)
    {
        unset($this->data[$disk]);
        return $this;
    }

    public function reset()
    {
        $this->data = [];

        return $this;
    }

    public function updateDisk($disk, $data)
    {
        $this->data[$disk] = $data;

        return $this;
    }

    public function disks()
    {
        return array_keys($this->data);
    }

    public function toArray()
    {
        return $this->data;
    }

    public static function diskAndPath($disk, $path) {
        return (new static())->addDisk($disk, [
            'path' => $path,
        ]);
    }

    public static function unserialize($data) {
        if (is_string($data)) {
            $unserializedData = json_decode($data, true, 4);
        } else {
            $unserializedData = $data;
        }

        return (new static)->fill($unserializedData);
    }

    public function toJson($options = 0) {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | $options, 4);
    }
}
