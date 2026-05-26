<?php

namespace Zdearo\ModuleManager\Commands;

use Illuminate\Console\Command;
use Zdearo\ModuleManager\Exceptions\ModuleNotFoundException;
use Zdearo\ModuleManager\ModuleManager;

class ModuleMigrateRollbackCommand extends Command
{
    protected $signature = 'module:migrate-rollback {name}';

    protected $description = 'Rollback migrations for a specific module';

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

        $this->call('migrate:rollback', [
            '--path' => $migrationsPath,
            '--realpath' => true,
        ]);

        return self::SUCCESS;
    }
}
