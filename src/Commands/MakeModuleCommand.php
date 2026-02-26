<?php

namespace Zdtec\ModuleManager\Commands;

use Illuminate\Console\Command;

class MakeModuleCommand extends Command
{
    protected $signature = 'make:module {name}';

    protected $description = 'Create a new module';

    public function handle(): int
    {
        $name = $this->argument('name');
        $modulesPath = config('module-manager.path');
        $modulePath = $modulesPath . '/' . $name;

        if (is_dir($modulePath)) {
            $this->error("Module [{$name}] already exists.");

            return self::FAILURE;
        }

        $namespace = config('module-manager.namespace', 'Modules') . '\\' . $name;

        // Create directory structure (mirrors Laravel project layout)
        $directories = [
            $modulePath,
            $modulePath . '/app/Models',
            $modulePath . '/app/Services',
            $modulePath . '/app/Filament/Resources',
            $modulePath . '/app/Filament/Pages',
            $modulePath . '/app/Filament/Widgets',
            $modulePath . '/routes',
            $modulePath . '/database/migrations',
            $modulePath . '/config',
        ];

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Create files from stubs
        $stubsPath = __DIR__ . '/../Stubs';

        // module.json
        $this->createFromStub(
            $stubsPath . '/module.json.stub',
            $modulePath . '/module.json',
            ['{{ name }}' => $name]
        );

        // app/Provider.php
        $this->createFromStub(
            $stubsPath . '/provider.stub',
            $modulePath . '/app/Provider.php',
            ['{{ namespace }}' => $namespace]
        );

        // routes/web.php
        $this->createFromStub(
            $stubsPath . '/web-routes.stub',
            $modulePath . '/routes/web.php',
            ['{{ prefix }}' => strtolower($name)]
        );

        // config/module.php
        copy($stubsPath . '/config.stub', $modulePath . '/config/module.php');

        $this->info("Module [{$name}] created successfully.");

        return self::SUCCESS;
    }

    protected function createFromStub(string $stubPath, string $targetPath, array $replacements): void
    {
        $content = file_get_contents($stubPath);

        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );

        file_put_contents($targetPath, $content);
    }
}
