<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Composer\Psr4PathResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function is_dir;
use function ltrim;
use function rtrim;
use function str_ends_with;

final readonly class PhpFileFinder
{
    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
        private Psr4PathResolver $psr4PathResolver = new Psr4PathResolver(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function files(string $basePath): array
    {
        $files = [];

        foreach ($this->sourcePaths ?? $this->psr4PathResolver->paths($basePath) as $sourcePath) {
            $fullPath = rtrim($basePath, '/') . '/' . ltrim($sourcePath, '/');

            if (! is_dir($fullPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath));

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();
                if (str_ends_with($path, '.php')) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }
}
