<?php

namespace Zdearo\ModuleManager\Commands;

use Illuminate\Console\Command;
use Zdearo\ModuleManager\Support\ModuleCatalogInstaller;

class ModuleInstallCommand extends Command
{
    protected $signature = 'module:install {source} {module} {--ref=main} {--force}';

    protected $description = 'Install a module from a GitHub or local module catalog';

    public function handle(ModuleCatalogInstaller $installer): int
    {
        try {
            $installed = $installer->install(
                source: $this->argument('source'),
                moduleName: $this->argument('module'),
                ref: $this->option('ref') ?: 'main',
                force: (bool) $this->option('force'),
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Installed modules: ' . implode(', ', $installed));

        return self::SUCCESS;
    }
}
