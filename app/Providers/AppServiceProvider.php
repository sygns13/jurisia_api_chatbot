<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('path.public', function() {
        // Esto le dice a Laravel que la carpeta pública está un nivel
        // por encima de la carpeta raíz del proyecto (appJurisiaApiBot)
        return dirname(base_path());
    });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
