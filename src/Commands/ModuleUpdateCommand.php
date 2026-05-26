<?php

namespace Zdearo\ModuleManager\Commands;

use Illuminate\Console\Command;
use Zdearo\ModuleManager\Support\ModuleCatalogInstaller;

class ModuleUpdateCommand extends Command
{
    protected $signature = 'module:update {module?}';

    protected $description = 'Update installed catalog modules from their locked source';

    public function handle(ModuleCatalogInstaller $installer): int
    {
        try {
            $updated = $installer->update($this->argument('module'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Updated modules: ' . implode(', ', $updated));

        return self::SUCCESS;
    }
}
