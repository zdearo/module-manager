---
name: module-manager-development
description: Build and work with ZdearoTech Module Manager — creating modules, managing dependencies, writing module providers, routes, migrations, config, Filament integration, and reacting to module events.
---

# Module Manager Development

## When to use this skill

Use this skill when:

- Creating a new module or scaffolding module structure
- Writing a module's `Provider.php`, routes, models, services, or config
- Declaring dependencies between modules in `module.json`
- Enabling, disabling, or listing modules
- Running or rolling back module-specific migrations
- Integrating modules with Filament panels
- Listening to module lifecycle events (`ModuleEnabled`, `ModuleDisabled`, `ModuleBooted`)
- Interacting with the `ModuleManager` API programmatically

## Module structure

Every module lives in `modules/{ModuleName}/` and mirrors a standard Laravel project layout. It must contain a `module.json` manifest and an `app/Provider.php` service provider:

```
modules/{ModuleName}/
├── module.json
├── app/
│   ├── Provider.php
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

Always use `php artisan make:module {Name}` to create modules. Never create the structure manually.

### Autoload

The package registers PSR-4 namespaces at runtime via Composer's ClassLoader. No changes to the host `composer.json` are needed — create a module and it just works.

`Modules\Blog\Models\Post` resolves to `modules/Blog/app/Models/Post.php`, just like `App\Models\User` resolves to `app/Models/User.php` in Laravel.

## Writing module.json

The manifest is immutable — the package never writes to it. Keep it minimal and accurate:

```json
{
    "name": "LiveCommerce",
    "description": "Real-time commerce with live streaming",
    "version": "1.0.0",
    "dependencies": ["Auth", "Payment"]
}
```

Rules:
- `name` must exactly match the directory name
- `dependencies` is an array of module names (strings), not semver constraints
- Do not add `enabled`, `status`, or any state fields — state lives in `modules_statuses.json`

## Writing Provider.php

The provider must be at `modules/{ModuleName}/app/Provider.php` with the class name `Provider` and namespace `Modules\{ModuleName}`:

```php
<?php

namespace Modules\LiveCommerce;

use Illuminate\Support\ServiceProvider;

class Provider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LiveStreamService::class);
    }

    public function boot(): void
    {
        //
    }
}
```

Do not register routes, migrations, or config in the module's Provider — the ModuleManager handles that automatically. Use the Provider only for bindings, event listeners, and module-specific boot logic.

## Writing module routes

Routes go in `modules/{ModuleName}/routes/web.php`. They are auto-registered with the `web` middleware group:

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Models\Post;

Route::prefix('blog')->group(function () {
    Route::get('/', function () {
        return view('blog::index', ['posts' => Post::latest()->paginate(10)]);
    });

    Route::get('/{post}', function (Post $post) {
        return view('blog::show', ['post' => $post]);
    });
});
```

Use a prefix matching the module name (lowercase) to avoid route collisions between modules.

## Writing module config

Place config in `modules/{ModuleName}/config/module.php`. It is merged under `config('modules.{name_lower}')`:

```php
<?php

return [
    'posts_per_page' => 15,
    'allow_comments' => true,
];
```

Access it:

```php
config('modules.blog.posts_per_page'); // 15
```

## Writing module migrations

Place migration files in `modules/{ModuleName}/database/migrations/`. Use standard Laravel migration naming:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

Migrations from all enabled modules run automatically with `php artisan migrate`. To target one module:

```bash
php artisan module:migrate Blog
php artisan module:migrate-rollback Blog
```

## Writing module models

Place Eloquent models in `modules/{ModuleName}/app/Models/`:

```php
<?php

namespace Modules\Blog\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'body'];
}
```

## Managing module state

Enable, disable, and list modules via Artisan:

```bash
php artisan module:enable Blog
php artisan module:disable Blog
php artisan module:disable Blog --force
php artisan module:disable Blog --cascade
php artisan module:list
```

Programmatically:

```php
use ZdearoTech\ModuleManager\ModuleManager;

$manager = app(ModuleManager::class);

// Enable
$manager->enable('Blog');

// Disable (with options)
$manager->disable('Blog');
$manager->disable('Blog', force: true);
$manager->disable('Blog', cascade: true);

// Query
$all = $manager->all();          // array<string, Module>
$enabled = $manager->enabled();  // array<string, Module>
$blog = $manager->find('Blog');  // ?Module
$blog = $manager->findOrFail('Blog'); // Module or throws ModuleNotFoundException
```

## Dependency management

When declaring dependencies, ensure:

1. The dependency module exists in the `modules/` directory
2. The dependency module is enabled before enabling the dependent module
3. No circular dependency chains exist

```json
{
    "name": "LiveCommerce",
    "dependencies": ["Auth", "Payment"]
}
```

Enabling `LiveCommerce` will fail if `Auth` or `Payment` are not enabled. Disabling `Auth` will fail if `LiveCommerce` is enabled (unless `--force` or `--cascade`).

## Listening to module events

React to module lifecycle changes using standard Laravel event listeners:

```php
<?php

namespace App\Listeners;

use ZdearoTech\ModuleManager\Events\ModuleEnabled;

class HandleModuleEnabled
{
    public function handle(ModuleEnabled $event): void
    {
        $module = $event->module;

        logger("Module {$module->name} v{$module->version} was enabled");
    }
}
```

Available events:

| Event | Fired when |
|---|---|
| `ZdearoTech\ModuleManager\Events\ModuleEnabled` | After a module is enabled |
| `ZdearoTech\ModuleManager\Events\ModuleDisabled` | After a module is disabled |
| `ZdearoTech\ModuleManager\Events\ModuleBooted` | After each module is registered during application boot |

Register listeners in `EventServiceProvider` or via `Event::listen()`:

```php
use Illuminate\Support\Facades\Event;
use ZdearoTech\ModuleManager\Events\ModuleBooted;

Event::listen(ModuleBooted::class, function (ModuleBooted $event) {
    // Runs for each enabled module during boot
});
```

## Filament integration

To auto-discover Filament Resources, Pages, and Widgets from modules, register the plugin once in your `PanelProvider`:

```php
use ZdearoTech\ModuleManager\Filament\FilamentModulePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(FilamentModulePlugin::make());
}
```

Then place Filament classes in the module's `app/Filament/` directory:

```
modules/Blog/app/Filament/Resources/PostResource.php
modules/Blog/app/Filament/Pages/BlogDashboard.php
modules/Blog/app/Filament/Widgets/LatestPostsWidget.php
```

Example resource:

```php
<?php

namespace Modules\Blog\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Forms;
use Modules\Blog\Models\Post;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required(),
            Forms\Components\RichEditor::make('body')->required(),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('title')->searchable(),
            Tables\Columns\TextColumn::make('created_at')->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \Modules\Blog\Filament\Resources\PostResource\Pages\ListPosts::route('/'),
            'create' => \Modules\Blog\Filament\Resources\PostResource\Pages\CreatePost::route('/create'),
            'edit' => \Modules\Blog\Filament\Resources\PostResource\Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
```

When the module is disabled, all its Filament components disappear from the panel automatically.

## Module object reference

The `Module` value object exposes:

```php
$module->name;                          // "Blog"
$module->description;                   // "Handles blog posts"
$module->version;                       // "1.0.0"
$module->dependencies;                  // ["Auth"]
$module->path;                          // "/path/to/modules/Blog"
$module->isEnabled();                   // bool
$module->getNamespace();                // "Modules\Blog"
$module->getProviderClass();            // "Modules\Blog\Provider"
$module->getAppPath();                  // ".../modules/Blog/app"
$module->getRoutesPath();               // ".../modules/Blog/routes/web.php"
$module->getMigrationsPath();           // ".../modules/Blog/database/migrations"
$module->getConfigPath();               // ".../modules/Blog/config/module.php"
$module->getFilamentPath('Resources');  // ".../modules/Blog/app/Filament/Resources"
$module->toArray();                     // Array representation
```

## Common mistakes to avoid

- Do not add `enabled` or `status` fields to `module.json` — state is managed in `modules_statuses.json`
- Do not register routes, migrations, or config in the module's `Provider.php` — the ModuleManager does this automatically
- Do not create module directories manually — always use `php artisan make:module`
- Do not modify `modules_statuses.json` directly — use `module:enable` / `module:disable` or the `ModuleManager` API
- Do not use semver constraints in dependencies — only module names are supported (v1)
- Do not place module code outside the `modules/` directory — the auto-discovery only scans the configured path
- All PHP classes (Models, Services, Filament, Provider) go inside `app/` — non-class files (routes, migrations, config) go in their respective lowercase directories
