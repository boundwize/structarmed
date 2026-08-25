<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\File\PhpFileCollector;
use Boundwize\StructArmed\File\SkipPathMatcher;
use Boundwize\StructArmed\Util\Path;

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
     * @param list<string>|null $scopeFiles
     * @return list<string>
     */
    public function files(string $basePath, array $skipPaths = [], ?array $scopeFiles = null): array
    {
        $sourcePaths = array_values(array_unique($this->sourcePaths ?? $this->psr4PathResolver->paths($basePath)));

        if ($scopeFiles !== null) {
            return $this->filesFromScope($scopeFiles, $basePath, $sourcePaths, $skipPaths);
        }

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
     * @param list<string> $scopeFiles
     * @param list<string> $sourcePaths
     * @param list<string> $skipPaths
     * @return list<string>
     */
    private function filesFromScope(
        array $scopeFiles,
        string $basePath,
        array $sourcePaths,
        array $skipPaths,
    ): array {
        $filesByPath     = [];
        $skipPathMatcher = SkipPathMatcher::compile($basePath, $skipPaths);

        foreach ($scopeFiles as $file) {
            $file = Path::normalise($file, canonicalise: true);

            if (! str_ends_with($file, '.php') || $skipPathMatcher->isSkipped($file)) {
                continue;
            }

            $filesByPath[$file] = $file;
        }

        $files = [];

        foreach ($sourcePaths as $sourcePath) {
            $resolvedSourcePath = Path::resolve($sourcePath, $basePath);
            $directoryPrefix    = Path::normalise($resolvedSourcePath, canonicalise: true) . '/';

            foreach ($filesByPath as $file) {
                if (! str_starts_with($file, $directoryPrefix)) {
                    continue;
                }

                $files[] = $file;
                unset($filesByPath[$file]);
            }
        }

        return $files;
    }
}
