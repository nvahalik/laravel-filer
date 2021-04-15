<?php

namespace Nvahalik\Filer\MetadataRepository;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Contracts\MetadataRepository;
use Nvahalik\Filer\Metadata;

class Database extends Base implements MetadataRepository
{
    protected string $table = 'filer_metadata';

    private string $connection;

    public function __construct(
        string $connection = null
    ) {
        $this->connection = $connection ?? 'default';
    }

    /**
     * @inheritdoc
     */
    public function getVisibility(string $path)
    {
        return $this->valueOrFalse($path, 'visibility');
    }

    private function newQuery()
    {
        return DB::connection($this->connection)
            ->table($this->table)
            ->where('disk', '=', $this->storageId);
    }

    private function read($path)
    {
        return $this->newQuery()
                ->where('path', '=', $path)
                ->first() ?? false;
    }

    /**
     * @inheritdoc
     */
    public function getTimestamp(string $path)
    {
        return $this->valueOrFalse($path, 'timestamp');
    }

    /**
     * @inheritdoc
     */
    public function getMimetype(string $path)
    {
        return $this->valueOrFalse($path, 'mimetype');
    }

    /**
     * @inheritdoc
     */
    public function getSize(string $path)
    {
        return $this->valueOrFalse($path, 'size');
    }

    /**
     * @inheritdoc
     */
    public function getMetadata(string $path): ?Metadata
    {
        if ($metadata = $this->read($path)) {
            $metadata = Metadata::deserialize((array) $metadata);
        }

        return $metadata ?: null;
    }

    public function listContents(string $directory = '', bool $recursive = false)
    {
        return $this->newQuery()
            ->when($recursive, function ($query) use ($directory) {
                $query->where('path', 'LIKE', "$directory%");
            }, function ($query) use ($directory) {
                $query->where('path', 'LIKE', "$directory%")
                    ->where('path', 'NOT LIKE', "$directory%/%");
            })->cursor()
            ->map(function ($record) {
                $record->timestamp = Carbon::parse($record->timestamp)->format('U');
                $record->backing_data = BackingData::unserialize($record->backing_data);

                return $record;
            });
    }

    public function has(string $path): bool
    {
        return $this->newQuery()
            ->where('path', '=', $path)
            ->exists();
    }

    public function setVisibility(string $path, string $visibility)
    {
        $this->newQuery()
            ->where('path', '=', $path)
            ->update(['visibility' => $visibility]);
    }

    public function record(Metadata $metadata)
    {
        $updatePayload = $metadata->serialize();

        $updates = Arr::where(array_keys($updatePayload), fn ($a) => $a !== 'path');

        $updatePayload['timestamp'] = Carbon::parse($updatePayload['timestamp'])->toDateTimeString();
        $updatePayload['disk'] = $this->storageId;
        $updatePayload['id'] = $updatePayload['id'] ?? Str::uuid();

        $this->newQuery()
            ->upsert($updatePayload, ['id'], $updates);
    }

    public function delete(string $path)
    {
        $this->newQuery()
            ->where('path', '=', $path)
            ->delete();
    }

    public function setBackingData(string $path, BackingData $backingData)
    {
        $this->newQuery()
            ->where('path', '=', $path)
            ->update(['backing_data' => $backingData->toJson()]);
    }

    public function rename(string $oldPath, string $newPath)
    {
        $this->newQuery()
            ->where('path', '=', $oldPath)
            ->update(['path' => $newPath]);
    }
}
