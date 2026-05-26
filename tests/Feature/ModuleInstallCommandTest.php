<?php

namespace Zdearo\ModuleManager\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Zdearo\ModuleManager\ModuleManagerServiceProvider;

class ModuleInstallCommandTest extends TestCase
{
    private string $workspace;

    protected function getPackageProviders($app): array
    {
        return [
            ModuleManagerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->workspace = sys_get_temp_dir() . '/module-manager-test-' . bin2hex(random_bytes(6));

        $app['config']->set('module-manager.path', $this->workspace . '/app/modules');
        $app['config']->set('module-manager.lock_path', $this->workspace . '/app/modules.lock.json');
        $app['config']->set('module-manager.statuses_path', $this->workspace . '/app/storage/modules_statuses.json');
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            $this->deleteDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function test_install_copies_requested_module_and_dependencies_from_catalog_source(): void
    {
        $repository = $this->createRepositoryFixture([
            'Product' => [],
            'Pricing' => ['Product'],
        ]);

        $this->artisan('module:install', [
            'source' => 'path:' . $repository,
            'module' => 'Pricing',
            '--ref' => 'main',
        ])->assertSuccessful();

        $this->assertFileExists($this->workspace . '/app/modules/Product/module.json');
        $this->assertFileExists($this->workspace . '/app/modules/Pricing/module.json');

        $lock = $this->readLock();

        $this->assertSame('1.0.0', $lock['modules']['Product']['version']);
        $this->assertSame('main', $lock['modules']['Pricing']['ref']);
        $this->assertSame('path:' . $repository, $lock['modules']['Pricing']['source']);
        $this->assertSame(['Product'], $lock['modules']['Pricing']['dependencies']);
    }

    public function test_catalog_uses_module_manifest_as_version_and_dependency_source(): void
    {
        $repository = $this->createRepositoryFixture([
            'Product' => [],
            'Pricing' => ['Product'],
        ], includeManifestMetadataInCatalog: false);

        $this->artisan('module:install', [
            'source' => 'path:' . $repository,
            'module' => 'Pricing',
        ])->assertSuccessful();

        $this->assertFileExists($this->workspace . '/app/modules/Product/module.json');

        $lock = $this->readLock();

        $this->assertSame('main', $lock['modules']['Pricing']['ref']);
        $this->assertSame('1.0.0', $lock['modules']['Pricing']['version']);
        $this->assertSame(['Product'], $lock['modules']['Pricing']['dependencies']);
    }

    public function test_install_with_constraint_uses_best_matching_module_tag(): void
    {
        $repository = $this->createRepositoryFixture([
            'Product' => [],
        ], initializeGit: true);

        $this->writeModuleFixture($repository, 'Product', [], '1.1.0');
        $this->commitRepository($repository, 'Product 1.1.0');
        $this->tagRepository($repository, 'product-v1.1.0');

        $this->writeModuleFixture($repository, 'Product', [], '1.2.0');
        $this->commitRepository($repository, 'Product 1.2.0');
        $this->tagRepository($repository, 'product-v1.2.0');

        $this->writeModuleFixture($repository, 'Product', [], '2.0.0');
        $this->commitRepository($repository, 'Product 2.0.0');
        $this->tagRepository($repository, 'product-v2.0.0');

        $this->artisan('module:install', [
            'source' => 'path:' . $repository,
            'module' => 'Product:^1.2',
        ])->assertSuccessful();

        $module = json_decode(file_get_contents($this->workspace . '/app/modules/Product/module.json'), true, flags: JSON_THROW_ON_ERROR);
        $lock = $this->readLock();

        $this->assertSame('1.2.0', $module['version']);
        $this->assertSame('product-v1.2.0', $lock['modules']['Product']['ref']);
        $this->assertSame('^1.2', $lock['modules']['Product']['constraint']);
    }

    public function test_update_reinstalls_locked_module_and_refreshes_lock_metadata(): void
    {
        $repository = $this->createRepositoryFixture([
            'Product' => [],
        ]);

        $this->artisan('module:install', [
            'source' => 'path:' . $repository,
            'module' => 'Product',
            '--ref' => 'main',
        ])->assertSuccessful();

        $this->writeModuleFixture($repository, 'Product', [], '1.1.0');

        $this->artisan('module:update', [
            'module' => 'Product',
        ])->assertSuccessful();

        $module = json_decode(file_get_contents($this->workspace . '/app/modules/Product/module.json'), true, flags: JSON_THROW_ON_ERROR);
        $lock = $this->readLock();

        $this->assertSame('1.1.0', $module['version']);
        $this->assertSame('1.1.0', $lock['modules']['Product']['version']);
    }

    public function test_install_skips_dependency_that_is_already_installed(): void
    {
        $repository = $this->createRepositoryFixture([
            'Product' => [],
            'Pricing' => ['Product'],
        ]);

        $this->artisan('module:install', [
            'source' => 'path:' . $repository,
            'module' => 'Product',
            '--ref' => 'main',
        ])->assertSuccessful();

        $this->artisan('module:install', [
            'source' => 'path:' . $repository,
            'module' => 'Pricing',
            '--ref' => 'main',
        ])->assertSuccessful();

        $lock = $this->readLock();

        $this->assertArrayHasKey('Product', $lock['modules']);
        $this->assertArrayHasKey('Pricing', $lock['modules']);
    }

    public function test_remove_deletes_installed_module_and_lock_entry(): void
    {
        $repository = $this->createRepositoryFixture([
            'Product' => [],
        ]);

        $this->artisan('module:install', [
            'source' => 'path:' . $repository,
            'module' => 'Product',
            '--ref' => 'main',
        ])->assertSuccessful();

        $this->artisan('module:remove', [
            'module' => 'Product',
        ])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->workspace . '/app/modules/Product');
        $this->assertArrayNotHasKey('Product', $this->readLock()['modules']);
    }

    /**
     * @param array<string, array<int, string>> $modules
     */
    private function createRepositoryFixture(
        array $modules,
        bool $includeManifestMetadataInCatalog = true,
        bool $initializeGit = false,
    ): string
    {
        $path = $this->workspace . '/repo';
        mkdir($path . '/modules', 0777, true);

        $catalog = [
            'name' => 'fixture/modules',
            'modules' => [],
        ];

        foreach ($modules as $name => $dependencies) {
            $this->writeModuleFixture($path, $name, $dependencies);

            $catalog['modules'][$name] = [
                'path' => 'modules/' . $name,
            ];

            if ($includeManifestMetadataInCatalog) {
                $catalog['modules'][$name]['version'] = '1.0.0';
                $catalog['modules'][$name]['dependencies'] = $dependencies;
            }
        }

        file_put_contents($path . '/modules.json', json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($initializeGit) {
            $this->runProcess($path, 'git init -b main');
            $this->runProcess($path, 'git config user.email "tests@example.test"');
            $this->runProcess($path, 'git config user.name "Tests"');
            $this->commitRepository($path, 'Initial catalog');
        }

        return $path;
    }

    /**
     * @param array<int, string> $dependencies
     */
    private function writeModuleFixture(string $repository, string $name, array $dependencies, string $version = '1.0.0'): void
    {
        $modulePath = $repository . '/modules/' . $name;

        if (! is_dir($modulePath . '/app')) {
            mkdir($modulePath . '/app', 0777, true);
        }

        file_put_contents($modulePath . '/module.json', json_encode([
            'name' => $name,
            'description' => $name . ' module',
            'version' => $version,
            'dependencies' => $dependencies,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($modulePath . '/app/Provider.php', "<?php\n\nnamespace Modules\\{$name};\n\nclass Provider\n{\n}\n");
    }

    private function commitRepository(string $repository, string $message): void
    {
        $this->runProcess($repository, 'git add .');
        $this->runProcess($repository, 'git commit -m ' . escapeshellarg($message));
    }

    private function tagRepository(string $repository, string $tag): void
    {
        $this->runProcess($repository, 'git tag ' . escapeshellarg($tag));
    }

    private function runProcess(string $workingDirectory, string $command): string
    {
        $output = [];
        $exitCode = 0;

        exec('cd ' . escapeshellarg($workingDirectory) . ' && ' . $command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->fail("Command failed [{$command}]: " . implode("\n", $output));
        }

        return implode("\n", $output);
    }

    /**
     * @return array{modules: array<string, mixed>}
     */
    private function readLock(): array
    {
        return json_decode(file_get_contents($this->workspace . '/app/modules.lock.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
