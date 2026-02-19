# ZdearoTech Module Manager for Laravel

A lightweight modular architecture package for Laravel. Each module is self-contained with its own routes, models, migrations, config, services, and optional Filament integration. Disable a module and everything disappears. Enable it and everything comes back. Simple.

## Requirements

- PHP 8.2+
- Laravel 11 or 12

## Installation

```bash
composer require zdearo-tech/module-manager
```

The service provider is auto-discovered. No manual registration needed.

### Publish the config (optional)

```bash
php artisan vendor:publish --tag=module-manager-config
```

This publishes `config/module-manager.php`:

```php
return [
    'path'          => base_path('modules'),     // Where modules live
    'namespace'     => 'Modules',                // Root namespace for all modules
    'statuses_path' => storage_path('app/modules_statuses.json'), // Enabled/disabled state
];
```

## Creating a Module

```bash
php artisan make:module Blog
```

This creates the following structure inside `modules/Blog/`:

```
modules/Blog/
├── module.json           # Manifest (name, version, dependencies)
├── Provider.php          # ServiceProvider
├── Routes/
│   └── web.php
├── Models/
├── Filament/
│   ├── Resources/
│   ├── Pages/
│   └── Widgets/
├── Migrations/
├── Config/
│   └── config.php
└── Services/
```

By default, the command also adds the `Modules\\` namespace to your host application's `composer.json` autoload and runs `composer dump-autoload`. To skip this:

```bash
php artisan make:module Blog --no-autoload
```

## Module Manifest

Each module has an immutable `module.json` that describes it:

```json
{
    "name": "Blog",
    "description": "Blog module for the application",
    "version": "1.0.0",
    "dependencies": ["Auth"]
}
```

- **name** — Module name (must match the directory name)
- **description** — Optional description
- **version** — Module version
- **dependencies** — Array of module names this module depends on (by name, no semver constraints)

> The `module.json` is never modified by the package. It only describes the module.

## Module State (Enable / Disable)

Module state is tracked in `storage/app/modules_statuses.json`, separate from the module code:

```json
{
    "Blog": true,
    "LiveCommerce": false
}
```

- A module **not present** in this file defaults to **enabled**
- The file lives in `storage/` by default (outside of git)
- Path is configurable via `config('module-manager.statuses_path')`

### Enable a module

```bash
php artisan module:enable Blog
```

Validates that all dependencies exist and are enabled before enabling.

### Disable a module

```bash
php artisan module:disable Blog
```

Fails if other enabled modules depend on it. Use flags to override:

```bash
# Ignore dependents and disable anyway
php artisan module:disable Blog --force

# Disable this module AND all modules that depend on it (recursively)
php artisan module:disable Blog --cascade
```

### List all modules

```bash
php artisan module:list
```

Outputs a table with Name, Version, Status, and Dependencies.

## What Gets Registered

When a module is enabled, the package automatically registers:

1. **ServiceProvider** — `Modules\Blog\Provider` (if the class exists)
2. **Routes** — `modules/Blog/Routes/web.php` with `web` middleware
3. **Migrations** — `modules/Blog/Migrations/` included in `php artisan migrate`
4. **Config** — `modules/Blog/Config/config.php` merged under `config('modules.blog')`

When a module is disabled, none of the above is registered.

## Migrations

All enabled modules' migrations are included when you run the standard Laravel migrate:

```bash
php artisan migrate
```

To run or rollback migrations for a specific module only:

```bash
# Run migrations for Blog only
php artisan module:migrate Blog

# Rollback migrations for Blog only
php artisan module:migrate-rollback Blog
```

## Dependencies

Modules can declare dependencies on other modules in their `module.json`:

```json
{
    "name": "LiveCommerce",
    "version": "1.0.0",
    "dependencies": ["Auth", "Payment"]
}
```

The dependency system enforces the following rules:

- **On enable**: all dependencies must exist and be enabled
- **On disable**: fails if other enabled modules depend on this one (unless `--force` or `--cascade`)
- **On boot**: modules are loaded in dependency order (topological sort)
- **Circular dependencies** are detected and raise an error

## Events

The package dispatches standard Laravel events:

| Event | When | Property |
|---|---|---|
| `ModuleEnabled` | After `module:enable` | `Module $module` |
| `ModuleDisabled` | After `module:disable` | `Module $module` |
| `ModuleBooted` | After each module is registered during boot | `Module $module` |

### Listening to events

```php
// In a ServiceProvider or EventServiceProvider
use ZdearoTech\ModuleManager\Events\ModuleEnabled;

Event::listen(ModuleEnabled::class, function (ModuleEnabled $event) {
    logger("Module {$event->module->name} was enabled");
});
```

## Filament Integration (Opt-in)

If you use [Filament](https://filamentphp.com), register the plugin in your `PanelProvider`:

```php
use ZdearoTech\ModuleManager\Filament\FilamentModulePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(FilamentModulePlugin::make());
}
```

The plugin automatically discovers Resources, Pages, and Widgets from every enabled module's `Filament/` directory:

```
modules/Blog/
└── Filament/
    ├── Resources/
    │   └── PostResource.php
    ├── Pages/
    │   └── BlogDashboard.php
    └── Widgets/
        └── LatestPostsWidget.php
```

Disable the module and all its Filament components disappear from the panel.

## Programmatic Usage

You can interact with the `ModuleManager` directly:

```php
use ZdearoTech\ModuleManager\ModuleManager;

$manager = app(ModuleManager::class);

// Get all modules
$all = $manager->all();

// Get only enabled modules
$enabled = $manager->enabled();

// Find a module (returns null if not found)
$blog = $manager->find('Blog');

// Find a module or throw ModuleNotFoundException
$blog = $manager->findOrFail('Blog');

// Enable / disable
$manager->enable('Blog');
$manager->disable('Blog', force: true);
$manager->disable('Blog', cascade: true);
```

### Module object

```php
$module = $manager->findOrFail('Blog');

$module->name;              // "Blog"
$module->description;       // ""
$module->version;           // "1.0.0"
$module->dependencies;      // ["Auth"]
$module->path;              // "/path/to/modules/Blog"
$module->isEnabled();       // true
$module->getNamespace();    // "Modules\Blog"
$module->getProviderClass();    // "Modules\Blog\Provider"
$module->getRoutesPath();       // "/path/to/modules/Blog/Routes/web.php"
$module->getMigrationsPath();   // "/path/to/modules/Blog/Migrations"
$module->getConfigPath();       // "/path/to/modules/Blog/Config/config.php"
$module->getFilamentPath('Resources'); // "/path/to/modules/Blog/Filament/Resources"
$module->toArray();
```

## Octane Compatibility

The package works seamlessly with Laravel Octane:

- **Development** (`--watch`): The `modules_statuses.json` file is automatically added to the Octane watch list. Changes trigger a reload.
- **Production**: The `module:enable` and `module:disable` commands automatically call `octane:reload` when Octane is detected.
- **FPM**: No special handling needed. State is re-scanned on every request.

## Artisan Commands Reference

| Command | Description |
|---|---|
| `make:module {name}` | Create a new module with full directory structure |
| `make:module {name} --no-autoload` | Create module without modifying `composer.json` |
| `module:enable {name}` | Enable a module |
| `module:disable {name}` | Disable a module |
| `module:disable {name} --force` | Disable ignoring dependents |
| `module:disable {name} --cascade` | Disable module and all its dependents recursively |
| `module:list` | List all modules with status |
| `module:migrate {name}` | Run migrations for a specific module |
| `module:migrate-rollback {name}` | Rollback migrations for a specific module |

## License

MIT
