<?php

namespace Zdearo\ModuleManager\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zdearo\ModuleManager\Module;

class ModuleEnabled
{
    use Dispatchable;

    public function __construct(public Module $module) {}
}
