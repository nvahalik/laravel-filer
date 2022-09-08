<?php

namespace Nvahalik\Filer\Contracts;

use League\Flysystem\Config;
use Nvahalik\Filer\BackingData;

interface AdapterStrategy
{
    public function setOptions(array $options);

    public function setBackingDisks(array $backingDisks);

    public function setOriginalDisks(array $originalDisks);

    public function getDisk(string $disk);

    public function writeStream($path, $stream, Config $config): ?BackingData;

    public function write($path, $contents, Config $config): ?BackingData;

    public function readStream(BackingData $backingData);

    public function read(BackingData $backingData);

//    public function update($path, $contents, Config $config, $backingData): ?BackingData;
//
//    public function updateStream($path, $stream, Config $config, $backingData): ?BackingData;

    public function delete(string $path, $backingData);

    public function copy(BackingData $source, string $destination): ?BackingData;
}
