<?php

namespace Nvahalik\Filer;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use League\Flysystem\Util;

/**
 * Class MetadataRepository.
 *
 * @property string path
 */
class Metadata implements Arrayable, Jsonable
{
    public ?string $id;

    public string $path;

    public ?string $etag;

    public string $filename;

    public ?string $mimetype;

    public int $created_at;

    public int $updated_at;

    public int $timestamp;

    public string $visibility = 'private';

    public static function deserialize($array)
    {
        $timestamp = Carbon::parse($array['timestamp'] ?? $array['updated_at'] ?? $array['created_at']);

        return new static(
            $array['path'],
            $array['mimetype'] ?? 'application/octet-stream',
            $array['size'],
            $array['etag'] ?? '',
            $timestamp->format('U'),
            $array['visibility'] ?? 'private',
            BackingData::unserialize($array['backing_data'] ?? []),
            $array['id'] ?? null
        );
    }

    public static function import($array)
    {
        return new static(
            $array['path'],
            $array['mimetype'] ?? 'application/octet-stream',
            $array['size'],
            $array['etag'] ?? '',
            $array['timestamp'] ?? $array['updated_at'] ?? $array['created_at'],
            $array['visibility'] ?? 'private',
            $array['id'] ?? null
        );
    }

    /**
     * @param string $path
     *
     * @return Metadata
     */
    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function setTimestamp(int $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /**
     * @param mixed|string $filename
     *
     * @return Metadata
     */
    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * @param string|null $mimetype
     *
     * @return Metadata
     */
    public function setMimetype(string $mimetype = null): self
    {
        $this->mimetype = $mimetype;

        return $this;
    }

    /**
     * @param string $visibility
     *
     * @return Metadata
     */
    public function setVisibility(string $visibility): self
    {
        $this->visibility = $visibility;

        return $this;
    }

    /**
     * @param int $size
     *
     * @return Metadata
     */
    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @param BackingData $backingData
     *
     * @return Metadata
     */
    public function setBackingData(BackingData $backingData): self
    {
        $this->backingData = $backingData;

        return $this;
    }

    public int $size;

    public BackingData $backingData;

    public function __construct(
        string $path,
        string $mimetype = 'application/octet-stream',
        int $size = 0,
        string $etag = null,
        int $timestamp = null,
        string $visibility = null,
        BackingData $backingData = null,
        string $id = null
    ) {
        $this->path = $path;
        $this->mimetype = $mimetype;
        $this->size = $size;
        $this->etag = $etag;
        $this->backingData = $backingData ?? new BackingData();
        $this->visibility = $visibility ?? 'private';
        $this->created_at = $timestamp ?? time();
        $this->updated_at = $timestamp ?? time();
        $this->timestamp = $timestamp ?? time();
        $this->id = $id;
    }

    public static function generateEtag($content)
    {
        $data = is_resource($content) ? stream_get_contents($content) : $content;

        return md5($data);
    }

    public static function generate($path, $contents): Metadata
    {
        $mimetype = Util::guessMimeType($path, $contents);
        if (is_resource($contents)) {
            $size = Util::contentSize(stream_get_contents($contents));
            rewind($contents);
        } else {
            $size = Util::contentSize($contents);
        }

        $etag = static::generateEtag($contents);

        return new static(
            $path,
            $mimetype,
            $size,
            $etag,
        );
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'path'         => $this->path,
            'etag'         => $this->etag,
            'mimetype'     => $this->mimetype,
            'visibility'   => $this->visibility,
            'size'         => $this->size,
            'backing_data' => $this->backingData,
            'timestamp'    => $this->timestamp,
        ];
    }

    public function serialize()
    {
        $data = $this->toArray();

        $data['backing_data'] = $data['backing_data']->toJson();

        return $data;
    }

    public function updateContents($contents)
    {
        $this->mimetype = Util::guessMimeType($this->path, $contents);
        if (is_resource($contents)) {
            $this->size = Util::contentSize(stream_get_contents($contents));
            rewind($contents);
        } else {
            $this->size = Util::contentSize($contents);
        }
        $this->etag = $this->generateEtag($contents);
        $this->updated_at = time();

        return $this;
    }

    public function toJson($options = 0)
    {
        // TODO: Implement toJson() method.
    }
}
