<?php

namespace Jonquintero\ModuleMaker;

use Illuminate\Support\ServiceProvider;
use Jonquintero\ModuleMaker\Console\CreateModule;

class ModuleMakerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/module-maker.php', 'module-maker');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateModule::class,
            ]);

            // Publicar stubs (para que el proyecto pueda sobreescribirlos)
            $this->publishes([
                __DIR__.'/stubs' => base_path('stubs/vendor/module-maker'),
            ], 'module-maker-stubs');

            // Publicar config
            $this->publishes([
                __DIR__.'/../config/module-maker.php' => config_path('module-maker.php'),
            ], 'module-maker-config');
        }
    }
}
