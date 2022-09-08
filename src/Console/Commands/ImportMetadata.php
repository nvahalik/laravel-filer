<?php

namespace Nvahalik\Filer\Console\Commands;

use Carbon\Carbon;
use GuzzleHttp\Psr7\MimeType;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
//use League\Flysystem\Util\MimeType;
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
    protected $signature = "filer:import-s3-metadata
            {storageId : The storageId to import the data into. The is the 'id' within your disk configuration.}  
            {file : The output from `s3cmd ls -lr` to import.}
            {--mode=ignore : Action to take if the file already exists. append backing store data, overwrite it, ignore - skip it.}
            {--disk= : The name of the backing disk the imports belong to. Default will auto-detect.}
            {--visibility= : Visibility for all files. Default will auto-detect with a fallback to private.}
            {--strip= : The file prefix to strip.}
            {--P|progress : Show progress.}
    ";

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
        $mode = $this->option('mode');

        if (! $filename) {
            return 0;
        }

        if ($this->option('progress')) {
            $bar = $this->output->createProgressBar();
            $bar->setFormat('verbose_nomax');
            $bar->start();
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

        /** @var Metadata $entry */
        foreach ($entries as $entry) {
            if ($this->repository->has($entry->path)) {
                if ($mode === 'ignore') {
                    continue;
                } elseif ($mode === 'overwrite') {
                    $existingEntry = $this->repository->getMetadata($entry->path);
                    $existingEntry->setBackingData($entry->backingData);
                    $this->repository->record($existingEntry);
                } elseif ($mode === 'append') {
                    $existingEntry = $this->repository->getMetadata($entry->path);
                    $this->appendBackingData($existingEntry, $entry);
                    $this->repository->record($existingEntry);
                }
            } else {
                $this->repository->record($entry);
            }

            $entry = null;

            if ($bar) {
                $bar->advance();
            }
        }

        if ($bar) {
            $bar->finish();
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

    private function appendBackingData(Metadata $existingEntry, Metadata $entry)
    {
        foreach ($entry->backingData->disks() as $disk) {
            $existingEntry->backingData->addDisk($disk, $entry->backingData->getDisk($disk));
        }
    }
}
