<?php

namespace Zdearo\ModuleManager\Commands;

use Illuminate\Console\Command;
use Zdearo\ModuleManager\Support\ModuleCatalogInstaller;

class ModuleRemoveCommand extends Command
{
    protected $signature = 'module:remove {module} {--force}';

    protected $description = 'Remove an installed catalog module';

    public function handle(ModuleCatalogInstaller $installer): int
    {
        try {
            $installer->remove(
                moduleName: $this->argument('module'),
                force: (bool) $this->option('force'),
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Removed module [{$this->argument('module')}].");

        return self::SUCCESS;
    }
}
