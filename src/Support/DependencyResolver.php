<?php

namespace Zdtec\ModuleManager\Support;

use Zdtec\ModuleManager\Exceptions\DependencyException;
use Zdtec\ModuleManager\Module;

class DependencyResolver
{
    /**
     * @param  array<string, Module>  $modules
     */
    public function __construct(protected array $modules) {}

    /**
     * Topological sort via Kahn's algorithm.
     *
     * @param  array<string, Module>  $modules
     * @return array<Module>
     */
    public function resolve(array $modules): array
    {
        $graph = [];
        $inDegree = [];

        foreach ($modules as $name => $module) {
            $graph[$name] = [];
            $inDegree[$name] = 0;
        }

        foreach ($modules as $name => $module) {
            foreach ($module->dependencies as $dep) {
                if (isset($modules[$dep])) {
                    $graph[$dep][] = $name;
                    $inDegree[$name]++;
                }
            }
        }

        $queue = [];
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $sorted = [];

        while (! empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $modules[$current];

            foreach ($graph[$current] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        if (count($sorted) !== count($modules)) {
            throw new DependencyException('Circular dependency detected among modules.');
        }

        return $sorted;
    }

    /**
     * Validate that all dependencies of a module exist and are enabled.
     */
    public function validateDependencies(Module $module): void
    {
        foreach ($module->dependencies as $dep) {
            if (! isset($this->modules[$dep])) {
                throw new DependencyException(
                    "Module [{$module->name}] depends on [{$dep}], which does not exist."
                );
            }

            if (! $this->modules[$dep]->isEnabled()) {
                throw new DependencyException(
                    "Module [{$module->name}] depends on [{$dep}], which is not enabled."
                );
            }
        }
    }

    /**
     * Return list of enabled modules that depend on the given module.
     *
     * @return array<Module>
     */
    public function checkDependents(Module $module): array
    {
        $dependents = [];

        foreach ($this->modules as $other) {
            if ($other->name === $module->name) {
                continue;
            }

            if ($other->isEnabled() && in_array($module->name, $other->dependencies)) {
                $dependents[] = $other;
            }
        }

        return $dependents;
    }

    /**
     * Return the full list of modules to disable in cascade (recursive).
     *
     * @return array<Module>
     */
    public function getCascadeDisableList(Module $module): array
    {
        $list = [];
        $this->collectCascade($module, $list);

        return array_values($list);
    }

    protected function collectCascade(Module $module, array &$list): void
    {
        foreach ($this->modules as $other) {
            if (isset($list[$other->name]) || $other->name === $module->name) {
                continue;
            }

            if ($other->isEnabled() && in_array($module->name, $other->dependencies)) {
                $list[$other->name] = $other;
                $this->collectCascade($other, $list);
            }
        }
    }
}
