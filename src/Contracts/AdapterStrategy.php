<?php

namespace Nvahalik\Filer\Contracts;

use Nvahalik\Filer\BackingData;

interface AdapterStrategy
{
    public function setOptions(array $options);

    public function setBackingDisks(array $backingDisks);

    public function setOriginalDisks(array $originalDisks);

    public function writeStream($path, $stream): ?BackingData;

    public function write($path, $contents): ?BackingData;

    public function readStream(BackingData $backingData);

    public function read(BackingData $backingData);

    public function update($path, $contents, $backingData): ?BackingData;

    public function updateStream($path, $stream, $backingData): ?BackingData;

    public function delete(string $path, $backingData);

    public function copy(BackingData $source, string $destination): ?BackingData;

}
