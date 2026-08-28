<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\File\PhpFileCollector;
use Boundwize\StructArmed\File\SkipPathMatcher;
use Boundwize\StructArmed\Util\Path;

use function array_keys;
use function array_unique;
use function array_values;
use function is_dir;
use function str_ends_with;
use function str_starts_with;

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
    public function files(string $basePath, array $skipPaths = []): array
    {
        $sourcePaths     = $this->sourcePaths($basePath);
        $skipPathMatcher = SkipPathMatcher::compile($basePath, $skipPaths);
        $files           = [];

        foreach ($sourcePaths as $sourcePath) {
            $fullPath = Path::resolve($sourcePath, $basePath);

            if (! is_dir($fullPath)) {
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
        array $scopeFiles,
        array $skipPaths = [],
    ): array {
        $skipPathMatcher   = SkipPathMatcher::compile($basePath, $skipPaths);
        $directoryPrefixes = [];

        foreach ($this->sourcePaths($basePath) as $sourcePath) {
            $directoryPrefixes[] = Path::normalise(Path::resolve($sourcePath, $basePath), canonicalise: true) . '/';
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

            foreach ($directoryPrefixes as $directoryPrefix) {
                if (str_starts_with($scopeFile, $directoryPrefix)) {
                    $files[$scopeFile] = true;
                    continue 2;
                }
            }
        }

        return array_keys($files);
    }

    /** @return list<string> */
    private function sourcePaths(string $basePath): array
    {
        return array_values(array_unique($this->sourcePaths ?? $this->psr4PathResolver->paths($basePath)));
    }
}
