<?php

namespace ZdearoTech\ModuleManager\Commands;

use Illuminate\Console\Command;

class MakeModuleCommand extends Command
{
    protected $signature = 'make:module {name} {--no-autoload}';

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

        // Create directory structure
        $directories = [
            $modulePath,
            $modulePath . '/Routes',
            $modulePath . '/Models',
            $modulePath . '/Filament/Resources',
            $modulePath . '/Filament/Pages',
            $modulePath . '/Filament/Widgets',
            $modulePath . '/Migrations',
            $modulePath . '/Config',
            $modulePath . '/Services',
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

        // Provider.php
        $this->createFromStub(
            $stubsPath . '/provider.stub',
            $modulePath . '/Provider.php',
            ['{{ namespace }}' => $namespace]
        );

        // Routes/web.php
        $this->createFromStub(
            $stubsPath . '/web-routes.stub',
            $modulePath . '/Routes/web.php',
            ['{{ prefix }}' => strtolower($name)]
        );

        // Config/config.php
        copy($stubsPath . '/config.stub', $modulePath . '/Config/config.php');

        $this->info("Module [{$name}] created successfully.");

        // Autoload setup
        if (! $this->option('no-autoload')) {
            $this->configureAutoload($namespace);
        }

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

    protected function configureAutoload(string $namespace): void
    {
        $composerPath = base_path('composer.json');

        if (! file_exists($composerPath)) {
            return;
        }

        $composer = json_decode(file_get_contents($composerPath), true);

        $psr4Key = $namespace . '\\';
        $modulesNamespace = config('module-manager.namespace', 'Modules') . '\\';

        // Add the root Modules\ namespace pointing to modules/ directory
        if (! isset($composer['autoload']['psr-4'][$modulesNamespace])) {
            $composer['autoload']['psr-4'][$modulesNamespace] = 'modules/';

            file_put_contents(
                $composerPath,
                json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );

            $this->info('Added autoload namespace to composer.json. Running dump-autoload...');
            exec('composer dump-autoload 2>&1', $output, $exitCode);

            if ($exitCode === 0) {
                $this->info('Autoload updated successfully.');
            } else {
                $this->warn('Failed to run dump-autoload. Run it manually: composer dump-autoload');
            }
        }
    }
}
