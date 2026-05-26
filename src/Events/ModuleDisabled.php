<?php

namespace Zdearo\ModuleManager\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zdearo\ModuleManager\Module;

class ModuleDisabled
{
    use Dispatchable;

    public function __construct(public Module $module) {}
}
