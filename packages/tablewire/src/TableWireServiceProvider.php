<?php

namespace TableWire;

use Illuminate\Support\ServiceProvider;

class TableWireServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tablewire.php', 'tablewire');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'tablewire');

        $this->publishes([
            __DIR__.'/../config/tablewire.php' => config_path('tablewire.php'),
        ], 'tablewire-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/tablewire'),
        ], 'tablewire-views');
    }
}
