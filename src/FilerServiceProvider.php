<?php

namespace Nvahalik\Filer;

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Nvahalik\Filer\AdapterStrategy\Factory;
use Nvahalik\Filer\Flysystem\FilerAdapter;
use Nvahalik\Filer\MetadataRepository\Json;

class FilerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->registerMigrations();
        }

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'filer-migrations');

        $this->publishes([
            __DIR__.'/../config/filer.php' => config_path('filer.php'),
        ], 'filer-config');

        Storage::extend('filer', function ($app, $config) {
            $backing_disks = array_combine($config['backing_disks'], array_map(function ($backing_disk) use ($app) {
                return $app->make('filesystem')->disk($backing_disk);
            }, $config['backing_disks']));

            $original_disks = array_combine($config['original_disks'], array_map(function ($backing_disk) use ($app) {
                return $app->make('filesystem')->disk($backing_disk);
            }, $config['original_disks']));

            $filerConfig = new Config(
                $config['id'],
                $backing_disks,
                $config['strategy'] ?? 'priority',
                $original_disks
            );

            $adapterStrategy = Factory::make(
                $config['adapter_strategy'],
                $filerConfig->backingDisks,
                $filerConfig->originalDisks,
                $config['adapter_strategy_config'] ?? []
            );

            return new Filesystem(
                new FilerAdapter(
                    $filerConfig,
                    (new Json)->setStorageId($config['id']),
                    $adapterStrategy
                ),
                $config
            );
        });
    }

    public function register()
    {
        /* @var $filesystem FilesystemManager */
        $filesystem = $this->app->make('filesystem');
    }

    /**
     * Register Passport's migration files.
     *
     * @return void
     */
    protected function registerMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
