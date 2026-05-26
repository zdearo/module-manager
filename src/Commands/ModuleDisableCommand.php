<?php

namespace Zdearo\ModuleManager\Commands;

use Illuminate\Console\Command;
use Zdearo\ModuleManager\Exceptions\DependencyException;
use Zdearo\ModuleManager\Exceptions\ModuleNotFoundException;
use Zdearo\ModuleManager\ModuleManager;

class ModuleDisableCommand extends Command
{
    protected $signature = 'module:disable {name} {--force} {--cascade}';

    protected $description = 'Disable a module';

    public function handle(ModuleManager $manager): int
    {
        $name = $this->argument('name');
        $force = $this->option('force');
        $cascade = $this->option('cascade');

        try {
            $manager->disable($name, $force, $cascade);
            $this->info("Module [{$name}] disabled successfully.");

            return self::SUCCESS;
        } catch (ModuleNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (DependencyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
