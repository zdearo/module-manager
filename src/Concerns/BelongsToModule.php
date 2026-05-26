<?php

namespace Zdearo\ModuleManager\Concerns;

use Illuminate\Support\Str;

trait BelongsToModule
{
    public function getTable(): string
    {
        if ($this->table !== null) {
            return $this->table;
        }

        $modulePrefix = static::resolveModulePrefix();
        $modelName = class_basename(static::class);
        $defaultTable = Str::snake(Str::pluralStudly($modelName));

        if (Str::snake($modelName) === $modulePrefix) {
            return $defaultTable;
        }

        return $modulePrefix . '_' . $defaultTable;
    }

    public function moduleTable(string $name): string
    {
        return static::resolveModulePrefix() . '_' . $name;
    }

    public static function resolveModulePrefix(): string
    {
        $namespace = (new \ReflectionClass(static::class))->getNamespaceName();
        $baseNamespace = config('module-manager.namespace', 'Modules');

        $relative = Str::after($namespace, $baseNamespace . '\\');
        $moduleName = Str::before($relative, '\\');

        return Str::snake($moduleName);
    }
}
