<?php
namespace Huangbule\LaravelEloquentFilter;

use Illuminate\Support\ServiceProvider;

class EloquentFilterProvider extends ServiceProvider {


    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filter.php', 'filter');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filter.php' => config_path('filter.php'),
            ], 'filter');

        }
    }


}