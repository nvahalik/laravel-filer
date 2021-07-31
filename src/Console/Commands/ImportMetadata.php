<?php

namespace Nvahalik\Filer\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use League\Flysystem\Util\MimeType;
use Nvahalik\Filer\BackingData;
use Nvahalik\Filer\Contracts\MetadataRepository;
use Nvahalik\Filer\Metadata;

class ImportMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'filer:import-s3-metadata
            {storageId : The disk/storageId to import the data into.}  
            {file : The output from `s3cmd ls -lr` to import.}
            {--disk= : The name of the backing disk the imports belong to. Default will auto-detect.}
            {--visibility= : Visibility for all files. Default will auto-detect and fallback to private.}
            {--strip= : The file prefix to strip.}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import S3 Metadata into the local Metadata repository.';
    private $buckets = [];
    private $diskName = null;
    private $defaultVisibility = null;
    private $stripFromFilename = null;

    /**
     * @var MetadataRepository
     */
    private MetadataRepository $repository;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->repository = app(MetadataRepository::class);

        $filename = $this->ensureFile($this->argument('file'));
        $storageId = $this->argument('storageId');

        if (! $filename) {
            return 0;
        }

        $this->repository->setStorageId($storageId);

        $this->diskName = $this->option('disk');
        $this->defaultVisibility = $this->option('visibility');
        $this->stripFromFilename = $this->option('strip');

        $entries = File::lines($filename)
            ->map(fn ($line) => Arr::flatten($this->parseLine($line)))
            ->filter()
            ->filter(fn ($line) => $line[1] !== '0')
            ->map(fn ($parsed) => $this->generateMetadata($parsed));

        foreach ($entries as $entry) {
            $this->repository->record($entry);
        }

        return 0;
    }

    private function ensureFile(?string $argument)
    {
        if (file_exists($argument) && is_readable($argument)) {
            return $argument;
        }

        $this->warn('Unable to find or read file: '.$argument);
    }

    private function parseLine($line)
    {
        $matches = [];
        preg_match_all('#^(\d{4}-\d{2}-\d{2} \d{2}:\d{2})\s+(\d+)\s+([\w-]+)\s+(\w+)\s+(.+)$#', $line, $matches);
        //                         Date- YYYY-MM-DD  Time HH:MM    ^Size^   ^Etag^    ^Vis^ ^Path^

        return array_slice($matches, 1);
    }

    private function generateMetadata($parsed)
    {
        $path = implode('/', array_slice(explode('/', $parsed[4]), 3));

        if ($this->stripFromFilename) {
            $path = str_replace($this->stripFromFilename, '', $path);
        }
        $disk = $this->diskName ?? $this->detectOriginalDisk($parsed[4]);

        return new Metadata(
            $path,
            MimeType::detectByFilename($path),
            (int) $parsed[1],
            $parsed[2],
            Carbon::parse($parsed[0])->format('U'),
            $this->getVisibility($parsed[4]),
            BackingData::diskAndPath($disk, $path)
        );
    }

    private function detectOriginalDisk($path)
    {
        $bucketName = array_slice(explode('/', $path), 2, 1)[0];

        if (! isset($this->buckets[$bucketName])) {
            $disks = config('filesystems.disks');

            foreach ($disks as $name => $config) {
                if ($config['driver'] === 's3' && $config['bucket'] === $bucketName) {
                    $this->buckets[$bucketName] = $name;
                }
            }
        }

        return $this->buckets[$bucketName];
    }

    private function getVisibility($path)
    {
        if ($this->defaultVisibility) {
            return $this->defaultVisibility;
        }

        $diskName = $this->detectOriginalDisk($path);

        return config('filesystems.disks')[$diskName]['visibility'] ?? 'private';
    }
}
