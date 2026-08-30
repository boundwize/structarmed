<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Composer;

use Boundwize\StructArmed\Util\Path;

use function array_merge;
use function array_values;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function trim;

final class Psr4PathResolver
{
    /**
     * Decoded composer.json per file, keyed by raw contents so a rewritten file
     * is re-decoded while repeated reads of an unchanged file are not.
     *
     * @var array<string, array{string, array<string, mixed>|null}>
     */
    private static array $decodedByFile = [];

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
        $composerFile = Path::normalise(Path::resolve('composer.json', $basePath), canonicalise: true);

        if (! file_exists($composerFile)) {
            return null;
        }

        $contents = (string) file_get_contents($composerFile);

        if (isset(self::$decodedByFile[$composerFile]) && self::$decodedByFile[$composerFile][0] === $contents) {
            return self::$decodedByFile[$composerFile][1];
        }

        self::$decodedByFile[$composerFile] = [$contents, $this->decode($contents)];

        return self::$decodedByFile[$composerFile][1];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $contents): ?array
    {
        $composer = json_decode($contents, true);

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
            $path = Path::normalise(trim($path));

            if ($path !== '' && $path !== '.') {
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
