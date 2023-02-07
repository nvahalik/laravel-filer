<?php

namespace Nvahalik\Filer;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
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

        $this->mergeConfigFrom(
            __DIR__.'/../config/filer.php', 'filer'
        );

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'filer-migrations');

        $this->publishes([
            __DIR__.'/../config/filer.php' => config_path('filer.php'),
        ], 'filer-config');

        $this->app->bind('laravel-filer', function (Application $app) {
            return new Filer($app);
        });

        Storage::extend('filer', static function (Application $app, $config) {
            [$filerConfig, $adapterStrategy] = Filer::getConfigAndAdapter($app, $config);

            $adapter = new FilerAdapter(
                $filerConfig,
                $app->make(MetadataRepository::class)->setStorageId($config['id']),
                $adapterStrategy
            );

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
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
