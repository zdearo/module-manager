<?php

namespace Zdtec\ModuleManager\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zdtec\ModuleManager\Module;

class ModuleDisabled
{
    use Dispatchable;

    public function __construct(public Module $module) {}
}
