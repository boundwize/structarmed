<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Composer\Psr4PathResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function fnmatch;
use function is_dir;
use function ltrim;
use function realpath;
use function rtrim;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

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
     * @param list<string> $skipPaths
     * @return list<string>
     */
    public function files(string $basePath, array $skipPaths = []): array
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
                if (str_ends_with($path, '.php') && ! $this->isSkipped($path, $basePath, $skipPaths)) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    /**
     * @param list<string> $skipPaths
     */
    private function isSkipped(string $filePath, string $basePath, array $skipPaths): bool
    {
        if ($skipPaths === []) {
            return false;
        }

        $normalisedFile = rtrim(str_replace('\\', '/', realpath($filePath) ?: $filePath), '/');
        $normalisedBase = rtrim(str_replace('\\', '/', realpath($basePath) ?: $basePath), '/');

        $relativePath = str_starts_with($normalisedFile, $normalisedBase . '/')
            ? substr($normalisedFile, strlen($normalisedBase) + 1)
            : $normalisedFile;

        foreach ($skipPaths as $skipPath) {
            $normalisedSkip = rtrim(str_replace('\\', '/', realpath($skipPath) ?: $skipPath), '/');

            if ($normalisedFile === $normalisedSkip || str_starts_with($normalisedFile, $normalisedSkip . '/')) {
                return true;
            }

            $fullSkipPath = $normalisedBase . '/' . ltrim(rtrim(str_replace('\\', '/', $skipPath), '/'), '/');
            $fullSkipPath = rtrim(str_replace('\\', '/', realpath($fullSkipPath) ?: $fullSkipPath), '/');
            if ($normalisedFile === $fullSkipPath || str_starts_with($normalisedFile, $fullSkipPath . '/')) {
                return true;
            }

            $rawSkip = rtrim(str_replace('\\', '/', $skipPath), '/');
            if (fnmatch($rawSkip, $normalisedFile) || fnmatch($rawSkip, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
