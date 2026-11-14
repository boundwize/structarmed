<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\File\PhpFileCollector;
use Boundwize\StructArmed\File\SkipPathMatcher;
use Boundwize\StructArmed\Util\Path;

use function array_keys;
use function array_unique;
use function array_values;
use function is_dir;
use function is_file;
use function str_ends_with;
use function str_starts_with;

/**
 * @internal
 */
final readonly class PhpFileFinder
{
    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
        private Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
        private PhpFileCollector $phpFileCollector = new PhpFileCollector(),
    ) {
    }

    /**
     * @param list<string> $skipPaths
     * @return list<string>
     */
    public function files(string $basePath, Architecture $architecture, array $skipPaths = []): array
    {
        $sourcePaths     = $this->sourcePaths($basePath, $architecture);
        $skipPathMatcher = SkipPathMatcher::compile($basePath, $skipPaths);
        $files           = [];

        foreach ($sourcePaths as $sourcePath) {
            $fullPath = Path::resolve($sourcePath, $basePath);

            if (! is_dir($fullPath)) {
                // a source path may point to a single PHP file
                if (is_file($fullPath) && str_ends_with($fullPath, '.php') && ! $skipPathMatcher->isSkipped($fullPath)) {
                    $files[] = Path::normalise($fullPath, canonicalise: true);
                }

                continue;
            }

            foreach ($this->phpFileCollector->collect($fullPath, $skipPathMatcher) as $file) {
                $files[] = $file;
            }
        }

        // ensure nothing duplicated once more
        // avoid inner directory provided by multiple source paths
        return array_values(array_unique($files));
    }

    /**
     * Selects configured PHP source files from an already discovered candidate set.
     *
     * @param list<string> $scopeFiles
     * @param list<string> $skipPaths
     * @return list<string>
     */
    public function filesFromScope(
        string $basePath,
        Architecture $architecture,
        array $scopeFiles,
        array $skipPaths = [],
    ): array {
        $skipPathMatcher   = SkipPathMatcher::compile($basePath, $skipPaths);
        $directoryPrefixes = [];
        $sourceFiles       = [];

        foreach ($this->sourcePaths($basePath, $architecture) as $sourcePath) {
            $normalisedSourcePath = Path::normalise(Path::resolve($sourcePath, $basePath), canonicalise: true);

            // a source path may point to a single PHP file, matched exactly
            $sourceFiles[$normalisedSourcePath] = true;
            $directoryPrefixes[]                = $normalisedSourcePath . '/';
        }

        $files = [];

        foreach ($scopeFiles as $scopeFile) {
            $scopeFile = Path::normalise($scopeFile, canonicalise: true);

            if (isset($files[$scopeFile])) {
                continue;
            }

            if (! str_ends_with($scopeFile, '.php')) {
                continue;
            }

            if ($skipPathMatcher->isSkipped($scopeFile)) {
                continue;
            }

            if (isset($sourceFiles[$scopeFile])) {
                $files[$scopeFile] = true;
                continue;
            }

            foreach ($directoryPrefixes as $directoryPrefix) {
                if (str_starts_with($scopeFile, $directoryPrefix)) {
                    $files[$scopeFile] = true;
                    continue 2;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * Without own source paths the finder follows the Analyser: a 'Source'
     * layer the architecture defines bounds the files, and only an undefined
     * or empty one falls back to the composer.json PSR-4 paths.
     *
     * @return list<string>
     */
    private function sourcePaths(string $basePath, Architecture $architecture): array
    {
        $sourcePaths = $this->sourcePaths ?? $this->definedSourcePaths($architecture);

        if ($sourcePaths === null) {
            return $this->psr4PathResolver->paths($basePath);
        }

        return array_values(array_unique($sourcePaths));
    }

    /** @return list<string>|null */
    private function definedSourcePaths(Architecture $architecture): ?array
    {
        $sourcePaths = $architecture->getLayers()['Source'] ?? null;

        if ($sourcePaths === null || $sourcePaths === []) {
            return null;
        }

        return (array) $sourcePaths;
    }
}
