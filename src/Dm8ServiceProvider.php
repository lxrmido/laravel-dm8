<?php

namespace Lmo\LaravelDm8;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Lmo\LaravelDm8\Connectors\DmConnector as Connector;

class Dm8ServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot Oci8 Provider.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/dm.php' => config_path('dm.php'),
        ], 'dm');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        if (file_exists(config_path('dm.php'))) {
            $this->mergeConfigFrom(config_path('dm.php'), 'database.connections');
        } else {
            $this->mergeConfigFrom(__DIR__.'/../config/dm.php', 'database.connections');
        }

        $this->app->bind('db.connector.dm', Connector::class);
        Connection::resolverFor('dm', static fn ($connection, $database, $prefix, $config) =>
            new Dm8Connection($connection, $database, $prefix, $config));
    }

    /**
     * Get the services provided by the provider.
     *
     * @return string[]
     */
    public function provides()
    {
        return [];
    }
}
