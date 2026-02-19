<?php

namespace ZdearoTech\ModuleManager\Events;

use Illuminate\Foundation\Events\Dispatchable;
use ZdearoTech\ModuleManager\Module;

class ModuleEnabled
{
    use Dispatchable;

    public function __construct(public Module $module) {}
}
