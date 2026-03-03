<?php

namespace Zdtec\ModuleManager;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Zdtec\ModuleManager\Events\ModuleBooted;
use Zdtec\ModuleManager\Events\ModuleDisabled;
use Zdtec\ModuleManager\Events\ModuleEnabled;
use Zdtec\ModuleManager\Exceptions\DependencyException;
use Zdtec\ModuleManager\Exceptions\ModuleNotFoundException;
use Zdtec\ModuleManager\Support\DependencyResolver;

class ModuleManager
{
    /** @var array<string, Module> */
    protected array $modules = [];

    protected array $statuses = [];

    public function __construct(protected Application $app) {}

    /**
     * Scan the modules directory and load module manifests + statuses.
     */
    public function discover(): void
    {
        $this->modules = [];
        $path = config('module-manager.path');

        if (! is_dir($path)) {
            return;
        }

        $this->statuses = $this->readStatuses();

        $directories = glob($path . '/*', GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            $manifestPath = $dir . '/module.json';

            if (! file_exists($manifestPath)) {
                continue;
            }

            $module = Module::fromPath($dir);

            // Module not in statuses = enabled by default
            if (array_key_exists($module->name, $this->statuses)) {
                $module->setEnabled((bool) $this->statuses[$module->name]);
            } else {
                $module->setEnabled(true);
            }

            $this->modules[$module->name] = $module;
        }
    }

    /**
     * Boot all enabled modules in dependency order.
     */
    public function boot(): void
    {
        $enabled = array_filter($this->modules, fn (Module $m) => $m->isEnabled());

        if (empty($enabled)) {
            return;
        }

        $resolver = new DependencyResolver($this->modules);
        $sorted = $resolver->resolve($enabled);

        foreach ($sorted as $module) {
            $this->registerModule($module);
            event(new ModuleBooted($module));
        }
    }

    /**
     * Register a single module: provider, routes, migrations, config.
     */
    protected function registerModule(Module $module): void
    {
        // 1. Register the ServiceProvider
        $providerClass = $module->getProviderClass();
        if (class_exists($providerClass)) {
            $this->app->register($providerClass);
        }

        // 2. Register routes
        $routesPath = $module->getRoutesPath();
        if (file_exists($routesPath)) {
            Route::middleware('web')->group($routesPath);
        }

        // 3. Register migrations path
        $migrationsPath = $module->getMigrationsPath();
        if (is_dir($migrationsPath)) {
            $this->app->afterResolving('migrator', function ($migrator) use ($migrationsPath) {
                $migrator->path($migrationsPath);
            });
        }

        // 4. Merge config
        $configPath = $module->getConfigPath();
        if (file_exists($configPath)) {
            $key = 'modules.' . strtolower($module->name);
            $this->app['config']->set($key, array_merge(
                $this->app['config']->get($key, []),
                require $configPath
            ));
        }

        // 5. Register Livewire component namespace
        $viewsPath = $module->getViewsPath();
        if (is_dir($viewsPath) && $this->app->bound('livewire')) {
            $this->app->make('livewire')->addNamespace(
                $module->getLivewireNamespace(),
                viewPath: $viewsPath,
            );
        }
    }

    /**
     * Enable a module.
     */
    public function enable(string $name): void
    {
        $module = $this->findOrFail($name);

        $resolver = new DependencyResolver($this->modules);
        $resolver->validateDependencies($module);

        $module->setEnabled(true);
        $this->writeStatus($name, true);

        event(new ModuleEnabled($module));

        $this->reloadOctaneIfRunning();
    }

    /**
     * Disable a module.
     */
    public function disable(string $name, bool $force = false, bool $cascade = false): void
    {
        $module = $this->findOrFail($name);

        $resolver = new DependencyResolver($this->modules);
        $dependents = $resolver->checkDependents($module);

        if (! empty($dependents) && ! $force && ! $cascade) {
            $names = implode(', ', array_map(fn (Module $m) => $m->name, $dependents));

            throw new DependencyException(
                "Cannot disable [{$name}]: the following enabled modules depend on it: {$names}. Use --force or --cascade."
            );
        }

        if ($cascade && ! empty($dependents)) {
            $cascadeList = $resolver->getCascadeDisableList($module);
            foreach ($cascadeList as $dependent) {
                $dependent->setEnabled(false);
                $this->writeStatus($dependent->name, false);
                event(new ModuleDisabled($dependent));
            }
        }

        $module->setEnabled(false);
        $this->writeStatus($name, false);

        event(new ModuleDisabled($module));

        $this->reloadOctaneIfRunning();
    }

    /**
     * Get all discovered modules.
     *
     * @return array<string, Module>
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Get only enabled modules.
     *
     * @return array<string, Module>
     */
    public function enabled(): array
    {
        return array_filter($this->modules, fn (Module $m) => $m->isEnabled());
    }

    /**
     * Find a module by name or throw.
     */
    public function findOrFail(string $name): Module
    {
        if (! isset($this->modules[$name])) {
            throw new ModuleNotFoundException($name);
        }

        return $this->modules[$name];
    }

    /**
     * Find a module by name (nullable).
     */
    public function find(string $name): ?Module
    {
        return $this->modules[$name] ?? null;
    }

    protected function readStatuses(): array
    {
        $path = config('module-manager.statuses_path');

        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);

        return json_decode($content, true) ?: [];
    }

    protected function writeStatus(string $name, bool $enabled): void
    {
        $path = config('module-manager.statuses_path');

        $statuses = $this->readStatuses();
        $statuses[$name] = $enabled;

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->statuses = $statuses;
    }

    protected function reloadOctaneIfRunning(): void
    {
        if (class_exists(\Laravel\Octane\Octane::class)) {
            try {
                Artisan::call('octane:reload');
            } catch (\Throwable) {
                // Octane not running, ignore
            }
        }
    }
}
