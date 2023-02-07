<?php

namespace Nvahalik\Filer;

use Illuminate\Foundation\Application;
use InvalidArgumentException;
use Nvahalik\Filer\AdapterStrategy\Factory;
use Nvahalik\Filer\Contracts\MetadataRepository;
use Nvahalik\Filer\Flysystem\FilerAdapter;

class Filer
{
    public function __construct(
        readonly private Application $app,
    ) {
    }

    public function disk(string $disk): FilerAdapter
    {
        // Is this actually a filer disk?
        if (config('filesystems.disks.'.$disk.'.driver') !== 'filer') {
            throw new InvalidArgumentException('Provided disk "'.$disk.'" is not a filer disk.');
        }

        $config = config('filesystems.disks.'.$disk);

        [$filerConfig, $adapterStrategy] = self::getConfigAndAdapter($this->app, $config);

        return new FilerAdapter(
            $filerConfig,
            $this->app->make(MetadataRepository::class)->setStorageId($config['id']),
            $adapterStrategy
        );
    }

    public static function getConfigAndAdapter(Application $app, $config): array
    {
        $backing_disks = array_combine($config['backing_disks'], array_map(static function ($backing_disk) use ($app) {
            return $app->make('filesystem')->disk($backing_disk);
        }, $config['backing_disks']));

        $config['original_disks'] = $config['original_disks'] ?? [];

        $original_disks = array_combine($config['original_disks'], array_map(static function ($backing_disk) use ($app) {
            return $app->make('filesystem')->disk($backing_disk);
        }, $config['original_disks']));

        $filerConfig = new Config(
            $config['id'],
            $backing_disks,
            $config['strategy'] ?? 'priority',
            $original_disks
        );

        $adapterStrategy = Factory::make(
            $config['adapter_strategy'] ?? 'basic',
            $filerConfig->backingDisks,
            $filerConfig->originalDisks,
            $config['adapter_strategy_config'] ?? []
        );

        return [$filerConfig, $adapterStrategy];
    }
}
