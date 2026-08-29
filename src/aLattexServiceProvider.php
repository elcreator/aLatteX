<?php

namespace Elcreator\aLatteX;

use Elcreator\aLatteX\Console\DemoInstallCommand;
use Elcreator\aLatteX\Console\DemoRemoveCommand;
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
        $this->registerLatteViewEngine();
        $this->declareTemplateFileEngine();
        $this->registerCommands();
    }

    /**
     * The demo installer, as two artisan commands. Registered only for a
     * console run: they are development scaffolding, and a web request has no
     * business being able to rewrite a site's templates.
     */
    private function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            DemoInstallCommand::class,
            DemoRemoveCommand::class,
        ]);
    }

    /**
     * Teach Laravel's view factory about .latte, which is all it takes for the
     * CMS to render a document from /views/<template alias>.latte: the factory
     * resolves whatever extension is registered with it, and the CMS resolves
     * template aliases through the factory.
     *
     * The extension is prepended to the factory's list, so a .latte file wins
     * over a .blade.php file of the same name.
     */
    private function registerLatteViewEngine(): void
    {
        if (!$this->app->bound('view')) {
            return;
        }

        $factory = $this->app['view'];
        if (!method_exists($factory, 'addExtension')) {
            return;
        }

        $app = $this->app;
        $factory->addExtension('latte', 'latte', static function () use ($app) {
            return new LatteViewEngine($app->make(LattexEngine::class));
        });
    }

    /**
     * Offer "Latte" in the manager's template form. Declaring the engine is
     * what puts it in the list; the CMS drops any declaration whose extension
     * the view factory cannot actually render, so this has to follow the
     * registration above.
     */
    private function declareTemplateFileEngine(): void
    {
        if (!function_exists('config')) {
            return;
        }

        config([
            'view.template_engines' => array_merge(
                (array) config('view.template_engines', []),
                ['latte' => ['label' => 'Latte', 'processor' => 'aLatteX']]
            ),
        ]);
    }
}
