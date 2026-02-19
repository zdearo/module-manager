<?php

namespace ZdearoTech\ModuleManager\Commands;

use Illuminate\Console\Command;
use ZdearoTech\ModuleManager\Exceptions\DependencyException;
use ZdearoTech\ModuleManager\Exceptions\ModuleNotFoundException;
use ZdearoTech\ModuleManager\ModuleManager;

class ModuleEnableCommand extends Command
{
    protected $signature = 'module:enable {name}';

    protected $description = 'Enable a module';

    public function handle(ModuleManager $manager): int
    {
        $name = $this->argument('name');

        try {
            $manager->enable($name);
            $this->info("Module [{$name}] enabled successfully.");

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
