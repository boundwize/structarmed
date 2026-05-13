<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Composer;

use function array_merge;
use function array_values;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function rtrim;
use function str_replace;
use function trim;

final class Psr4PathResolver
{
    /**
     * @return list<string>
     */
    public function paths(string $basePath): array
    {
        $composer = $this->composerConfig($basePath);

        if ($composer === null) {
            return [];
        }

        return $this->normalisePaths($this->psr4Paths($composer));
    }

    /**
     * @return array<string, list<string>>
     */
    public function namespacePaths(string $basePath): array
    {
        $composer = $this->composerConfig($basePath);

        if ($composer === null) {
            return [];
        }

        $mappings = [];

        foreach ($this->psr4Mappings($composer) as $namespace => $pathConfig) {
            $paths = [];

            foreach ((array) $pathConfig as $path) {
                if (is_string($path)) {
                    $paths[] = $path;
                }
            }

            $paths = $this->normalisePaths($paths);

            if ($paths === []) {
                continue;
            }

            $mappings[$this->normaliseNamespace($namespace)] = $paths;
        }

        return $mappings;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function composerConfig(string $basePath): ?array
    {
        $composerFile = rtrim($basePath, '/') . '/composer.json';

        if (! file_exists($composerFile)) {
            return null;
        }

        $composer = json_decode((string) file_get_contents($composerFile), true);

        if (! is_array($composer)) {
            return null;
        }

        $config = [];

        foreach ($composer as $key => $value) {
            if (is_int($key)) {
                return null;
            }

            $config[$key] = $value;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $composer
     * @return list<string>
     */
    private function psr4Paths(array $composer): array
    {
        $paths = [];

        foreach ($this->psr4Mappings($composer) as $pathConfig) {
            foreach ((array) $pathConfig as $path) {
                if (is_string($path)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $composer
     * @return array<string, mixed>
     */
    private function psr4Mappings(array $composer): array
    {
        $mappings = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $composer[$section] ?? [];

            if (! is_array($autoload)) {
                continue;
            }

            $psr4 = $autoload['psr-4'] ?? [];

            if (! is_array($psr4)) {
                continue;
            }

            foreach ($psr4 as $namespace => $pathConfig) {
                if (is_string($namespace)) {
                    $mappings[$namespace] = array_merge(
                        $mappings[$namespace] ?? [],
                        (array) $pathConfig
                    );
                }
            }
        }

        return $mappings;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalisePaths(array $paths): array
    {
        $normalised = [];

        foreach ($paths as $path) {
            $path = rtrim(str_replace('\\', '/', trim($path)), '/');

            if ($path !== '') {
                $normalised[$path] = $path;
            }
        }

        return array_values($normalised);
    }

    private function normaliseNamespace(string $namespace): string
    {
        $namespace = trim($namespace, '\\');

        return $namespace === '' ? '' : $namespace . '\\';
    }
}
