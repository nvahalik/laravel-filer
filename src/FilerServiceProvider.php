<?php

namespace Nvahalik\Filer;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Nvahalik\Filer\AdapterStrategy\Factory;
use Nvahalik\Filer\Console\Commands\ImportMetadata;
use Nvahalik\Filer\Contracts\MetadataRepository;
use Nvahalik\Filer\Flysystem\FilerAdapter;
use Nvahalik\Filer\MetadataRepository\Database;
use Nvahalik\Filer\MetadataRepository\Json;
use Nvahalik\Filer\MetadataRepository\Memory;

class FilerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->registerMigrations();
            $this->registerCommand();
        }

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'filer-migrations');

        $this->publishes([
            __DIR__.'/../config/filer.php' => config_path('filer.php'),
        ], 'filer-config');

        Storage::extend('filer', function (Application $app, $config) {
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
                    $app->make(MetadataRepository::class)->setStorageId($config['id']),
                    $adapterStrategy
                ),
                $config
            );
        });
    }

    public function register()
    {
        $this->app->singleton(MetadataRepository::class, function ($app, $config) {
            switch ($app['config']['filer']['metadata']) {
                case 'json':
                    return new Json($app['config']['filer']['json']['storage_path']);
                case 'database':
                    return new Database($app['config']['filer']['database']['connection']);
                case 'memory':
                default:
                    return new Memory();
            }
        });
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

    private function registerCommand()
    {
        $this->commands([
            ImportMetadata::class,
        ]);
    }
}
