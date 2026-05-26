## Zdearo Module Manager

This package provides a lightweight modular architecture for Laravel. Each module is a self-contained unit with its own routes, models, migrations, config, services, and optional Filament integration. Disabling a module removes all of its registrations; enabling it brings everything back.

### Module Directory Convention

Every module lives under the `modules/` directory (configurable via `config('module-manager.path')`) and mirrors a standard Laravel project layout:

@verbatim
```
modules/{ModuleName}/
├── module.json              ← Immutable manifest (name, version, dependencies)
├── app/
│   ├── Provider.php         ← ServiceProvider (class: Modules\{ModuleName}\Provider)
│   ├── Models/
│   ├── Services/
│   └── Filament/
│       ├── Resources/
│       ├── Pages/
│       └── Widgets/
├── routes/
│   └── web.php              ← Routes (auto-registered with 'web' middleware)
├── database/
│   └── migrations/          ← Auto-included in `php artisan migrate` when enabled
└── config/
    └── module.php           ← Merged under config('modules.{name_lower}')
```
@endverbatim

### Key Rules

- **`module.json` is immutable** — it only describes the module (name, version, dependencies). Never modify it programmatically.
- **Module state** (enabled/disabled) is stored in `storage/app/modules_statuses.json`, not in the module itself.
- **A new module not present in the statuses file defaults to enabled.**
- **The Provider class** must always be at `app/Provider.php` with class `Provider` and namespace `Modules\{ModuleName}`.
- **Autoload is automatic** — the package registers PSR-4 namespaces at runtime via Composer's ClassLoader. No changes to the host `composer.json` needed. All PHP classes (Models, Services, Filament, Provider) live inside `app/`.
- **Dependencies are by name only** (no semver). Example: `"dependencies": ["Auth", "Payment"]`.

### Creating a Module

Use the Artisan command — never create modules manually:

@verbatim
<code-snippet name="Create a new module" lang="bash">
php artisan make:module Blog
</code-snippet>
@endverbatim

This scaffolds the full directory structure and generates `module.json`, `app/Provider.php`, `routes/web.php`, and `config/module.php` from stubs. Autoload is handled at runtime — no `composer.json` changes or `dump-autoload` needed.

### module.json Format

@verbatim
<code-snippet name="module.json manifest" lang="json">
{
    "name": "Blog",
    "description": "Handles blog posts and categories",
    "version": "1.0.0",
    "dependencies": ["Auth"]
}
</code-snippet>
@endverbatim

### Enable / Disable Modules

@verbatim
<code-snippet name="Enable and disable modules" lang="bash">
php artisan module:enable Blog
php artisan module:disable Blog
php artisan module:disable Blog --force    # Ignore dependents
php artisan module:disable Blog --cascade  # Disable dependents recursively
</code-snippet>
@endverbatim

### Migrations

All enabled modules' migrations are automatically included in `php artisan migrate`. For per-module control:

@verbatim
<code-snippet name="Per-module migrations" lang="bash">
php artisan module:migrate Blog
php artisan module:migrate-rollback Blog
</code-snippet>
@endverbatim

### Module Config Access

A module's `config/module.php` is merged under a dot-notated key:

@verbatim
<code-snippet name="Access module config" lang="php">
// modules/Blog/config/module.php returns ['posts_per_page' => 10]
$value = config('modules.blog.posts_per_page'); // 10
</code-snippet>
@endverbatim

### Events

The package dispatches three events. Listen to them to react to module state changes:

- `Zdearo\ModuleManager\Events\ModuleEnabled` — after enabling
- `Zdearo\ModuleManager\Events\ModuleDisabled` — after disabling
- `Zdearo\ModuleManager\Events\ModuleBooted` — after each module is registered during boot

Each event has a public `Module $module` property.

### Programmatic Usage

@verbatim
<code-snippet name="Using ModuleManager programmatically" lang="php">
use Zdearo\ModuleManager\ModuleManager;

$manager = app(ModuleManager::class);

$manager->all();              // All discovered modules
$manager->enabled();          // Only enabled modules
$manager->find('Blog');       // Find by name (nullable)
$manager->findOrFail('Blog'); // Find or throw ModuleNotFoundException

$manager->enable('Blog');
$manager->disable('Blog', force: true);
$manager->disable('Blog', cascade: true);
</code-snippet>
@endverbatim

### Filament Integration (Opt-in)

Register the plugin in your `PanelProvider` to auto-discover Filament Resources, Pages, and Widgets from all enabled modules:

@verbatim
<code-snippet name="Register Filament plugin" lang="php">
use Zdearo\ModuleManager\Filament\FilamentModulePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(FilamentModulePlugin::make());
}
</code-snippet>
@endverbatim

### Dependency Rules

- **On enable**: all dependencies must exist and be enabled, or it throws `DependencyException`.
- **On disable**: fails if other enabled modules depend on this one (use `--force` or `--cascade`).
- **On boot**: modules are loaded in topological (dependency) order.
- **Circular dependencies** are detected and raise a `DependencyException`.

### Available Artisan Commands

| Command | Description |
|---|---|
| `make:module {name}` | Scaffold a new module |
| `module:enable {name}` | Enable a module |
| `module:disable {name} [--force] [--cascade]` | Disable a module |
| `module:list` | List all modules with status |
| `module:migrate {name}` | Run migrations for one module |
| `module:migrate-rollback {name}` | Rollback migrations for one module |
