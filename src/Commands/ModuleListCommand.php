<?php

namespace Zdearo\ModuleManager\Commands;

use Illuminate\Console\Command;
use Zdearo\ModuleManager\ModuleManager;

class ModuleListCommand extends Command
{
    protected $signature = 'module:list';

    protected $description = 'List all modules';

    public function handle(ModuleManager $manager): int
    {
        $modules = $manager->all();

        if (empty($modules)) {
            $this->info('No modules found.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($modules as $module) {
            $rows[] = [
                $module->name,
                $module->version,
                $module->isEnabled() ? '<fg=green>Enabled</>' : '<fg=red>Disabled</>',
                implode(', ', $module->dependencies) ?: '—',
            ];
        }

        $this->table(['Name', 'Version', 'Status', 'Dependencies'], $rows);

        return self::SUCCESS;
    }
}
