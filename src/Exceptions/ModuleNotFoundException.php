<?php

namespace ZdearoTech\ModuleManager\Exceptions;

use RuntimeException;

class ModuleNotFoundException extends RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct("Module [{$name}] not found.");
    }
}
