<?php

namespace ZdearoTech\ModuleManager\Commands;

use Illuminate\Console\Command;
use ZdearoTech\ModuleManager\Exceptions\ModuleNotFoundException;
use ZdearoTech\ModuleManager\ModuleManager;

class ModuleMigrateCommand extends Command
{
    protected $signature = 'module:migrate {name}';

    protected $description = 'Run migrations for a specific module';

    public function handle(ModuleManager $manager): int
    {
        $name = $this->argument('name');

        try {
            $module = $manager->findOrFail($name);
        } catch (ModuleNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $migrationsPath = $module->getMigrationsPath();

        if (! is_dir($migrationsPath)) {
            $this->info("No migrations directory found for module [{$name}].");

            return self::SUCCESS;
        }

        $this->call('migrate', [
            '--path' => $migrationsPath,
            '--realpath' => true,
        ]);

        return self::SUCCESS;
    }
}
