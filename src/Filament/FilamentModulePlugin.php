<?php

namespace Zdtec\ModuleManager\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Zdtec\ModuleManager\ModuleManager;

class FilamentModulePlugin implements Plugin
{
    public static function make(): static
    {
        return new static;
    }

    public function getId(): string
    {
        return 'module-manager';
    }

    public function register(Panel $panel): void
    {
        $manager = app(ModuleManager::class);

        foreach ($manager->enabled() as $module) {
            $resourcesPath = $module->getFilamentPath('Resources');
            $pagesPath = $module->getFilamentPath('Pages');
            $widgetsPath = $module->getFilamentPath('Widgets');

            $namespace = $module->getNamespace() . '\\Filament';

            if (is_dir($resourcesPath)) {
                $panel->discoverResources(
                    in: $resourcesPath,
                    for: $namespace . '\\Resources'
                );
            }

            if (is_dir($pagesPath)) {
                $panel->discoverPages(
                    in: $pagesPath,
                    for: $namespace . '\\Pages'
                );
            }

            if (is_dir($widgetsPath)) {
                $panel->discoverWidgets(
                    in: $widgetsPath,
                    for: $namespace . '\\Widgets'
                );
            }
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
