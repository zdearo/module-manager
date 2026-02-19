<?php

namespace ZdearoTech\ModuleManager;

class Module
{
    protected bool $enabled = true;

    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $version,
        public readonly array $dependencies,
        public readonly string $path,
    ) {}

    public static function fromPath(string $path): static
    {
        $manifestPath = $path . '/module.json';

        if (! file_exists($manifestPath)) {
            throw new Exceptions\ModuleNotFoundException(basename($path));
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);

        return new static(
            name: $manifest['name'],
            description: $manifest['description'] ?? '',
            version: $manifest['version'] ?? '1.0.0',
            dependencies: $manifest['dependencies'] ?? [],
            path: $path,
        );
    }

    public function getProviderClass(): string
    {
        return $this->getNamespace() . '\\Provider';
    }

    public function getNamespace(): string
    {
        return config('module-manager.namespace', 'Modules') . '\\' . $this->name;
    }

    public function getRoutesPath(): string
    {
        return $this->path . '/Routes/web.php';
    }

    public function getMigrationsPath(): string
    {
        return $this->path . '/Migrations';
    }

    public function getConfigPath(): string
    {
        return $this->path . '/Config/config.php';
    }

    public function getFilamentPath(string $type): string
    {
        return $this->path . '/Filament/' . $type;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'version' => $this->version,
            'dependencies' => $this->dependencies,
            'path' => $this->path,
            'enabled' => $this->enabled,
        ];
    }
}
