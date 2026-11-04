<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\File;

use Boundwize\StructArmed\Util\Path;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function realpath;

/**
 * Recursively collects PHP files under a directory, pruning skipped paths
 * while traversing so skipped directories are never descended into.
 */
final readonly class PhpFileCollector
{
    /**
     * @return list<string>
     */
    public function collect(string $directory, SkipPathMatcher $skipPathMatcher): array
    {
        // Walking from the canonical root keeps every entry canonical, since symlinks
        // are pruned and entry names are joined verbatim, so no per-file realpath() is
        // needed. A filesystem root (its own parent) is excluded: SPL joins "/" children
        // as "//name", which Path::normalise() reads as a UNC path.
        $canonicalDirectory = realpath($directory);
        $isCanonical        = $canonicalDirectory !== false && dirname($canonicalDirectory) !== $canonicalDirectory;
        $rootDirectory      = $isCanonical ? $canonicalDirectory : $directory;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($rootDirectory, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file) use ($skipPathMatcher, $isCanonical): bool {
                    if ($file->isLink()) {
                        return false;
                    }

                    if (! $file->isDir() && $file->getExtension() !== 'php') {
                        return false;
                    }

                    return ! $skipPathMatcher->isSkipped($file->getPathname(), $isCanonical);
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $files[] = Path::normalise($file->getPathname(), canonicalise: ! $isCanonical);
        }

        return $files;
    }
}
