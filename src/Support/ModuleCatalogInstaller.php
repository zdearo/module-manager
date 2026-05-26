<?php

namespace Zdearo\ModuleManager\Support;

use Composer\Semver\Semver;
use Illuminate\Support\Str;
use RuntimeException;

class ModuleCatalogInstaller
{
    public function install(string $source, string $moduleName, string $ref = 'main', bool $force = false): array
    {
        [$moduleName, $constraint] = $this->parseModuleSpec($moduleName);
        $ref = $constraint !== null && $ref === 'main'
            ? $this->resolveRefForConstraint($source, $moduleName, $constraint)
            : $ref;

        $repositoryPath = $this->resolveSource($source, $ref);
        $catalog = $this->readCatalog($repositoryPath);
        $installed = [];

        foreach ($this->resolveInstallOrder($repositoryPath, $catalog, $moduleName) as $name) {
            $entry = $this->catalogEntry($catalog, $name);
            $manifest = $this->readManifest($repositoryPath . '/' . $entry['path']);
            $targetPath = $this->modulesPath() . '/' . $name;

            if ($name !== $moduleName && is_dir($targetPath) && ! $force) {
                continue;
            }

            $this->copyModule(
                sourcePath: $repositoryPath . '/' . $entry['path'],
                targetPath: $targetPath,
                force: $force
            );

            $lockEntry = [
                'source' => $source,
                'ref' => $ref,
                'path' => $entry['path'],
                'version' => $manifest['version'] ?? ($entry['version'] ?? '1.0.0'),
                'dependencies' => $manifest['dependencies'] ?? ($entry['dependencies'] ?? []),
                'installed_at' => date(DATE_ATOM),
            ];

            if ($name === $moduleName && $constraint !== null) {
                $lockEntry['constraint'] = $constraint;
            }

            $this->writeLockEntry($name, $lockEntry);

            $installed[] = $name;
        }

        if ($this->isTemporaryRepository($repositoryPath)) {
            $this->deleteDirectory($repositoryPath);
        }

        return $installed;
    }

    public function update(?string $moduleName = null): array
    {
        $lock = $this->readLock();
        $modules = $lock['modules'] ?? [];

        if ($moduleName !== null) {
            if (! isset($modules[$moduleName])) {
                throw new RuntimeException("Module [{$moduleName}] is not installed from a catalog.");
            }

            $modules = [$moduleName => $modules[$moduleName]];
        }

        $updated = [];

        foreach ($modules as $name => $entry) {
            $updated = array_values(array_unique(array_merge(
                $updated,
                $this->install($entry['source'], $name, $entry['ref'] ?? 'main', force: true)
            )));
        }

        return $updated;
    }

    public function remove(string $moduleName, bool $force = false): void
    {
        $lock = $this->readLock();
        $modules = $lock['modules'] ?? [];

        if (! $force) {
            $dependents = [];

            foreach ($modules as $name => $entry) {
                if ($name !== $moduleName && in_array($moduleName, $entry['dependencies'] ?? [], true)) {
                    $dependents[] = $name;
                }
            }

            if ($dependents !== []) {
                throw new RuntimeException(
                    "Cannot remove [{$moduleName}]: installed modules depend on it: " . implode(', ', $dependents)
                );
            }
        }

        $modulePath = $this->modulesPath() . '/' . $moduleName;

        if (is_dir($modulePath)) {
            $this->deleteDirectory($modulePath);
        }

        unset($lock['modules'][$moduleName]);
        $this->writeLock($lock);
    }

    protected function resolveSource(string $source, string $ref): string
    {
        if (str_starts_with($source, 'path:')) {
            $path = substr($source, 5);

            if (! is_dir($path)) {
                throw new RuntimeException("Source path [{$path}] does not exist.");
            }

            if ($this->isGitRepository($path) && $ref !== 'main') {
                return $this->clonePathRepository($path, $ref);
            }

            return rtrim($path, '/');
        }

        if (str_starts_with($source, 'github:')) {
            return $this->cloneGithubRepository(substr($source, 7), $ref);
        }

        throw new RuntimeException('Unsupported module source. Use github:owner/repo or path:/absolute/repo.');
    }

    protected function parseModuleSpec(string $moduleSpec): array
    {
        if (! str_contains($moduleSpec, ':')) {
            return [$moduleSpec, null];
        }

        [$moduleName, $constraint] = explode(':', $moduleSpec, 2);

        if ($moduleName === '' || $constraint === '') {
            throw new RuntimeException("Invalid module constraint [{$moduleSpec}]. Use Module:^1.2.");
        }

        return [$moduleName, $constraint];
    }

    protected function resolveRefForConstraint(string $source, string $moduleName, string $constraint): string
    {
        $tags = $this->listTags($source);
        $prefix = Str::kebab($moduleName) . '-v';
        $versionsByTag = [];

        foreach ($tags as $tag) {
            if (! str_starts_with(Str::lower($tag), $prefix)) {
                continue;
            }

            $version = substr($tag, strlen($prefix));
            $versionsByTag[$version] = $tag;
        }

        $matching = Semver::rsort(Semver::satisfiedBy(array_keys($versionsByTag), $constraint));

        if ($matching === []) {
            throw new RuntimeException("No tag found for module [{$moduleName}] satisfying [{$constraint}].");
        }

        return $versionsByTag[$matching[0]];
    }

    protected function listTags(string $source): array
    {
        if (str_starts_with($source, 'path:')) {
            $path = substr($source, 5);

            if (! $this->isGitRepository($path)) {
                throw new RuntimeException('Module constraints require a Git repository with module tags.');
            }

            return $this->runProcess($path, 'git tag --list');
        }

        if (str_starts_with($source, 'github:')) {
            $repository = substr($source, 7);

            if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
                throw new RuntimeException("Invalid GitHub repository [{$repository}].");
            }

            $lines = $this->runGithubProcess('git ls-remote --tags --refs ' . escapeshellarg($this->githubRepositoryUrl($repository)));
            $tags = [];

            foreach ($lines as $line) {
                if (preg_match('/refs\/tags\/(.+)$/', $line, $matches)) {
                    $tags[] = $matches[1];
                }
            }

            return $tags;
        }

        throw new RuntimeException('Unsupported module source. Use github:owner/repo or path:/absolute/repo.');
    }

    protected function clonePathRepository(string $path, string $ref): string
    {
        $target = sys_get_temp_dir() . '/module-manager-checkout-' . bin2hex(random_bytes(8));

        $this->runProcess(null, 'git clone --quiet --branch ' . escapeshellarg($ref) . ' --depth 1 ' . escapeshellarg($path) . ' ' . escapeshellarg($target));

        return $target;
    }

    protected function cloneGithubRepository(string $repository, string $ref): string
    {
        if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
            throw new RuntimeException("Invalid GitHub repository [{$repository}].");
        }

        $target = sys_get_temp_dir() . '/module-manager-checkout-' . bin2hex(random_bytes(8));

        $this->runGithubProcess('git clone --quiet --branch ' . escapeshellarg($ref) . ' --depth 1 ' . escapeshellarg($this->githubRepositoryUrl($repository)) . ' ' . escapeshellarg($target));

        return $target;
    }

    protected function githubRepositoryUrl(string $repository): string
    {
        return "https://github.com/{$repository}.git";
    }

    protected function runGithubProcess(string $command): array
    {
        $token = $this->githubToken();

        if ($token === null) {
            return $this->runProcess(null, 'GIT_TERMINAL_PROMPT=0 ' . $command);
        }

        $askPassPath = $this->writeGithubAskPassScript($token);

        try {
            return $this->runProcess(null, 'GIT_ASKPASS=' . escapeshellarg($askPassPath) . ' GIT_TERMINAL_PROMPT=0 ' . $command);
        } finally {
            @unlink($askPassPath);
        }
    }

    protected function githubToken(): ?string
    {
        foreach (['GITHUB_TOKEN', 'GH_TOKEN'] as $key) {
            $token = getenv($key);

            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        $composerAuth = getenv('COMPOSER_AUTH');

        if (is_string($composerAuth) && $composerAuth !== '') {
            $token = $this->githubTokenFromComposerAuth($composerAuth);

            if ($token !== null) {
                return $token;
            }
        }

        $home = getenv('HOME') ?: '';
        $composerHome = getenv('COMPOSER_HOME') ?: ($home !== '' ? $home . '/.config/composer' : '');
        $paths = array_filter(array_unique([
            $composerHome !== '' ? $composerHome . '/auth.json' : '',
            $home !== '' ? $home . '/.config/composer/auth.json' : '',
            $home !== '' ? $home . '/.composer/auth.json' : '',
        ]));

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $token = $this->githubTokenFromComposerAuth((string) file_get_contents($path));

            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }

    protected function githubTokenFromComposerAuth(string $json): ?string
    {
        $auth = json_decode($json, true);
        $token = is_array($auth) ? ($auth['github-oauth']['github.com'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    protected function writeGithubAskPassScript(string $token): string
    {
        $path = sys_get_temp_dir() . '/module-manager-github-askpass-' . bin2hex(random_bytes(8));
        $script = "#!/bin/sh\n"
            . "case \"\$1\" in\n"
            . '*Username*) printf ' . escapeshellarg('%s\n') . ' ' . escapeshellarg('x-access-token') . " ;;\n"
            . '*) printf ' . escapeshellarg('%s\n') . ' ' . escapeshellarg($token) . " ;;\n"
            . "esac\n";

        file_put_contents($path, $script);
        chmod($path, 0700);

        return $path;
    }

    protected function downloadGithubRepository(string $repository, string $ref): string
    {
        if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
            throw new RuntimeException("Invalid GitHub repository [{$repository}].");
        }

        $url = "https://github.com/{$repository}/archive/{$ref}.zip";
        $zipPath = sys_get_temp_dir() . '/module-manager-download-' . bin2hex(random_bytes(8)) . '.zip';
        $extractPath = sys_get_temp_dir() . '/module-manager-download-' . bin2hex(random_bytes(8));

        $contents = @file_get_contents($url);

        if ($contents === false) {
            throw new RuntimeException("Unable to download [{$url}].");
        }

        file_put_contents($zipPath, $contents);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            @unlink($zipPath);

            throw new RuntimeException("Unable to open downloaded archive [{$url}].");
        }

        mkdir($extractPath, 0777, true);
        $zip->extractTo($extractPath);
        $zip->close();
        @unlink($zipPath);

        $directories = glob($extractPath . '/*', GLOB_ONLYDIR) ?: [];

        if (count($directories) !== 1) {
            throw new RuntimeException("Downloaded archive [{$url}] did not contain a single repository root.");
        }

        return $directories[0];
    }

    protected function readCatalog(string $repositoryPath): array
    {
        $path = $repositoryPath . '/modules.json';

        if (! file_exists($path)) {
            throw new RuntimeException("Module catalog [modules.json] was not found in [{$repositoryPath}].");
        }

        $catalog = json_decode(file_get_contents($path), true);

        if (! is_array($catalog) || ! isset($catalog['modules']) || ! is_array($catalog['modules'])) {
            throw new RuntimeException('Module catalog must contain a modules object.');
        }

        return $catalog;
    }

    protected function readManifest(string $modulePath): array
    {
        $path = $modulePath . '/module.json';

        if (! file_exists($path)) {
            throw new RuntimeException("Module manifest [{$path}] was not found.");
        }

        $manifest = json_decode(file_get_contents($path), true);

        if (! is_array($manifest) || ! isset($manifest['name'])) {
            throw new RuntimeException("Module manifest [{$path}] is invalid.");
        }

        return $manifest;
    }

    protected function resolveInstallOrder(string $repositoryPath, array $catalog, string $moduleName): array
    {
        $resolved = [];
        $visiting = [];

        $visit = function (string $name) use (&$visit, &$resolved, &$visiting, $repositoryPath, $catalog): void {
            if (in_array($name, $resolved, true)) {
                return;
            }

            if (in_array($name, $visiting, true)) {
                throw new RuntimeException("Circular module dependency detected at [{$name}].");
            }

            $visiting[] = $name;
            $entry = $this->catalogEntry($catalog, $name);
            $manifest = $this->readManifest($repositoryPath . '/' . $entry['path']);

            foreach ($this->dependencyNames($manifest['dependencies'] ?? ($entry['dependencies'] ?? [])) as $dependency) {
                $visit($dependency);
            }

            $resolved[] = $name;
        };

        $visit($moduleName);

        return $resolved;
    }

    protected function catalogEntry(array $catalog, string $moduleName): array
    {
        $entry = $catalog['modules'][$moduleName] ?? null;

        if (! is_array($entry) || ! isset($entry['path'])) {
            throw new RuntimeException("Module [{$moduleName}] was not found in catalog.");
        }

        return $entry;
    }

    protected function copyModule(string $sourcePath, string $targetPath, bool $force): void
    {
        if (! is_dir($sourcePath)) {
            throw new RuntimeException("Module source path [{$sourcePath}] does not exist.");
        }

        if (is_dir($targetPath)) {
            if (! $force) {
                throw new RuntimeException("Module target [{$targetPath}] already exists. Use --force to overwrite.");
            }

            $this->deleteDirectory($targetPath);
        }

        $this->copyDirectory($sourcePath, $targetPath);
    }

    protected function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0777, true);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $destination = $target . '/' . $items->getSubPathName();

            if ($item->isDir()) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0777, true);
                }

                continue;
            }

            copy($item->getPathname(), $destination);
        }
    }

    protected function writeLockEntry(string $moduleName, array $entry): void
    {
        $lock = $this->readLock();
        $lock['modules'][$moduleName] = $entry;

        ksort($lock['modules']);

        $this->writeLock($lock);
    }

    protected function readLock(): array
    {
        $path = $this->lockPath();

        if (! file_exists($path)) {
            return ['modules' => []];
        }

        $lock = json_decode(file_get_contents($path), true);

        if (! is_array($lock) || ! isset($lock['modules']) || ! is_array($lock['modules'])) {
            throw new RuntimeException("Module lock [{$path}] is invalid.");
        }

        return $lock;
    }

    protected function writeLock(array $lock): void
    {
        $path = $this->lockPath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    protected function modulesPath(): string
    {
        $path = config('module-manager.path');

        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    protected function lockPath(): string
    {
        return config('module-manager.lock_path', base_path('modules.lock.json'));
    }

    protected function isTemporaryRepository(string $repositoryPath): bool
    {
        return str_starts_with($repositoryPath, sys_get_temp_dir() . '/module-manager-download-')
            || str_starts_with($repositoryPath, sys_get_temp_dir() . '/module-manager-checkout-');
    }

    protected function dependencyNames(array $dependencies): array
    {
        return array_is_list($dependencies) ? $dependencies : array_keys($dependencies);
    }

    protected function isGitRepository(string $path): bool
    {
        return is_dir(rtrim($path, '/') . '/.git');
    }

    protected function runProcess(?string $workingDirectory, string $command): array
    {
        $prefix = $workingDirectory === null ? '' : 'cd ' . escapeshellarg($workingDirectory) . ' && ';
        $output = [];
        $exitCode = 0;

        exec($prefix . $command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException("Command failed [{$command}]: " . implode("\n", $output));
        }

        return $output;
    }

    protected function deleteDirectory(string $path): void
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
