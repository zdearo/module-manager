<?php

namespace Zdearo\ModuleManager;

use Illuminate\Support\ServiceProvider;
use Zdearo\ModuleManager\Commands\MakeModuleCommand;
use Zdearo\ModuleManager\Commands\ModuleDisableCommand;
use Zdearo\ModuleManager\Commands\ModuleEnableCommand;
use Zdearo\ModuleManager\Commands\ModuleListCommand;
use Zdearo\ModuleManager\Commands\ModuleMigrateCommand;
use Zdearo\ModuleManager\Commands\ModuleMigrateRollbackCommand;

class ModuleManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/module-manager.php', 'module-manager');

        $this->app->singleton(ModuleManager::class, function ($app) {
            return new ModuleManager($app);
        });

        $manager = $this->app->make(ModuleManager::class);
        $manager->discover();

        $this->registerAutoload($manager);
    }

    public function boot(): void
    {
        $this->app->make(ModuleManager::class)->boot();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/module-manager.php' => config_path('module-manager.php'),
            ], 'module-manager-config');

            $this->commands([
                MakeModuleCommand::class,
                ModuleEnableCommand::class,
                ModuleDisableCommand::class,
                ModuleListCommand::class,
                ModuleMigrateCommand::class,
                ModuleMigrateRollbackCommand::class,
            ]);
        }

        $this->configureOctaneWatch();
    }

    protected function registerAutoload(ModuleManager $manager): void
    {
        $loader = require base_path('vendor/autoload.php');
        $namespace = config('module-manager.namespace', 'Modules');

        foreach ($manager->all() as $module) {
            $loader->addPsr4(
                $namespace . '\\' . $module->name . '\\',
                [$module->path . '/app/']
            );
        }
    }

    protected function configureOctaneWatch(): void
    {
        if (! class_exists(\Laravel\Octane\Octane::class)) {
            return;
        }

        $statusesPath = config('module-manager.statuses_path');

        $watches = config('octane.watch', []);

        if (! in_array($statusesPath, $watches)) {
            config(['octane.watch' => array_merge($watches, [$statusesPath])]);
        }
    }
}
