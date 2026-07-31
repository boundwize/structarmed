<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\File\PhpFileCollector;
use Boundwize\StructArmed\File\SkipPathMatcher;
use Boundwize\StructArmed\Util\Path;

use function array_fill_keys;
use function array_map;
use function array_unique;
use function array_values;
use function is_dir;

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
     * @param list<string> $scopeFiles
     * @return list<string>
     */
    public function files(
        string $basePath,
        array $skipPaths = [],
        array $scopeFiles = [],
        bool $isScopeFilesEnabled = false,
    ): array {
        $skipPathMatcher = SkipPathMatcher::compile($basePath, $skipPaths);
        $files           = [];
        $scopeFileMap    = $isScopeFilesEnabled
            ? array_fill_keys(array_map(
                static fn(string $file): string => Path::normalise($file, canonicalise: true),
                $scopeFiles,
            ), true)
            : [];

        $sourcePaths = array_unique($this->sourcePaths ?? $this->psr4PathResolver->paths($basePath));
        foreach ($sourcePaths as $sourcePath) {
            $fullPath = Path::resolve($sourcePath, $basePath);

            if (! is_dir($fullPath)) {
                continue;
            }

            foreach ($this->phpFileCollector->collect($fullPath, $skipPathMatcher) as $file) {
                if ($isScopeFilesEnabled && ! isset($scopeFileMap[$file])) {
                    continue;
                }

                $files[] = $file;
            }
        }

        // ensure nothing duplicated once more
        // avoid inner directory provided by multiple source paths
        return array_values(array_unique($files));
    }
}
