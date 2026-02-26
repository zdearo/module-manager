<?php

namespace Zdtec\ModuleManager\Support;

use Illuminate\Support\Str;

class ModuleTable
{
    public static function name(string $moduleName, string $table): string
    {
        return Str::snake($moduleName) . '_' . $table;
    }
}
