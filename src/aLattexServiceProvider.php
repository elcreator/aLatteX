<?php

namespace Elcreator\aLatteX;

use EvolutionCMS\ServiceProvider;

class aLattexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LattexEngine::class, function () {
            return new LattexEngine();
        });

        $this->loadPluginsFrom(dirname(__DIR__) . '/plugins/');
    }

    public function boot(): void
    {
        //
    }
}
