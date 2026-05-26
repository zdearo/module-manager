<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modules Path
    |--------------------------------------------------------------------------
    |
    | The path where modules are stored, relative to the base path.
    |
    */

    'path' => base_path('modules'),

    /*
    |--------------------------------------------------------------------------
    | Modules Namespace
    |--------------------------------------------------------------------------
    |
    | The root namespace for all modules.
    |
    */

    'namespace' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | Statuses Path
    |--------------------------------------------------------------------------
    |
    | Path to the modules_statuses.json file that tracks enabled/disabled state.
    |
    */

    'statuses_path' => storage_path('app/modules_statuses.json'),

    /*
    |--------------------------------------------------------------------------
    | Modules Lock Path
    |--------------------------------------------------------------------------
    |
    | Path to the modules.lock.json file that tracks modules installed from
    | remote or local module catalogs.
    |
    */

    'lock_path' => base_path('modules.lock.json'),

];
