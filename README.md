# Zdearo Module Manager for Laravel

A lightweight modular architecture package for Laravel. Each module is self-contained with its own routes, models, migrations, config, services, and optional Filament integration. Disable a module and everything disappears. Enable it and everything comes back. Simple.

## Requirements

- PHP 8.2+ (PHP 8.3+ when using Laravel 13)
- Laravel 11, 12, or 13

## Installation

```bash
composer require zdearo/module-manager
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
    'lock_path'     => base_path('modules.lock.json'), // Catalog install lock file
];
```

## Creating a Module

```bash
php artisan make:module Blog
```

This creates the following structure inside `modules/Blog/`, mirroring a standard Laravel project layout:

```
modules/Blog/
├── module.json              # Manifest (name, version, dependencies)
├── app/
│   ├── Provider.php         # ServiceProvider
│   ├── Models/
│   ├── Services/
│   └── Filament/
│       ├── Resources/
│       ├── Pages/
│       └── Widgets/
├── routes/
│   └── web.php
├── database/
│   └── migrations/
└── config/
    └── module.php
```

Autoload is handled automatically at runtime by the package — no changes to `composer.json` and no `dump-autoload` needed. Create a module and it just works.

## Installing Modules From a Catalog

Modules can also be installed from a GitHub repository that exposes a `modules.json` catalog:

```bash
php artisan module:install github:zdtec/erp-modules Product
php artisan module:install github:zdtec/erp-modules Pricing
```

By default, the installer downloads the `main` archive, reads `modules.json`, installs the requested module and its manifest dependencies into `config('module-manager.path')`, then records the source in `modules.lock.json`.

To install a version range, put a constraint after the module name. The installer looks for module tags like `product-v1.2.0`, chooses the highest compatible tag, and verifies the installed `module.json` version:

```bash
php artisan module:install github:zdtec/erp-modules Product:^1.2
```

You can also pin any explicit Git ref:

```bash
php artisan module:install github:zdtec/erp-modules Product --ref=product-v1.2.3
```

For local development or tests, use a path source:

```bash
php artisan module:install path:/absolute/path/to/erp-modules Pricing
```

A catalog looks like this:

```json
{
    "name": "zdtec/erp-modules",
    "modules": {
        "Product": {
            "path": "modules/Product"
        },
        "Pricing": {
            "path": "modules/Pricing"
        }
    }
}
```

The catalog is only an index. Module versions and dependencies live in each module's `module.json`.

Installed modules are tracked in `modules.lock.json`:

```json
{
    "modules": {
        "Product": {
            "source": "github:zdtec/erp-modules",
            "ref": "main",
            "path": "modules/Product",
            "version": "1.0.0",
            "dependencies": [],
            "constraint": "^1.0",
            "installed_at": "2026-05-26T12:00:00+00:00"
        }
    }
}
```

Update or remove catalog-installed modules with:

```bash
php artisan module:update Product
php artisan module:update
php artisan module:remove Product
php artisan module:remove Product --force
```

`module:remove` refuses to remove a module when another locked module depends on it unless `--force` is used.

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
- **dependencies** — Array of module names this module depends on

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

1. **ServiceProvider** — `Modules\Blog\Provider` (from `app/Provider.php`)
2. **Routes** — `routes/web.php` with `web` middleware
3. **Migrations** — `database/migrations/` included in `php artisan migrate`
4. **Config** — `config/module.php` merged under `config('modules.blog')`

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
use Zdearo\ModuleManager\Events\ModuleEnabled;

Event::listen(ModuleEnabled::class, function (ModuleEnabled $event) {
    logger("Module {$event->module->name} was enabled");
});
```

## Filament Integration (Opt-in)

If you use [Filament](https://filamentphp.com), register the plugin in your `PanelProvider`:

```php
use Zdearo\ModuleManager\Filament\FilamentModulePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(FilamentModulePlugin::make());
}
```

The plugin automatically discovers Resources, Pages, and Widgets from every enabled module's `app/Filament/` directory:

```
modules/Blog/
└── app/
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
use Zdearo\ModuleManager\ModuleManager;

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
$module->getNamespace();        // "Modules\Blog"
$module->getProviderClass();    // "Modules\Blog\Provider"
$module->getAppPath();          // "/path/to/modules/Blog/app"
$module->getRoutesPath();       // "/path/to/modules/Blog/routes/web.php"
$module->getMigrationsPath();   // "/path/to/modules/Blog/database/migrations"
$module->getConfigPath();       // "/path/to/modules/Blog/config/module.php"
$module->getFilamentPath('Resources'); // "/path/to/modules/Blog/app/Filament/Resources"
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
| `module:enable {name}` | Enable a module |
| `module:disable {name}` | Disable a module |
| `module:disable {name} --force` | Disable ignoring dependents |
| `module:disable {name} --cascade` | Disable module and all its dependents recursively |
| `module:list` | List all modules with status |
| `module:migrate {name}` | Run migrations for a specific module |
| `module:migrate-rollback {name}` | Rollback migrations for a specific module |

## License

MIT
