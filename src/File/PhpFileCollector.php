<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\File;

use Boundwize\StructArmed\Util\Path;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function dirname;
use function realpath;

use const PHP_OS_FAMILY;

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
        // needed. Not so for a filesystem root (its own parent), whose children SPL joins
        // as "//name", nor on Windows, where isLink() misses junctions.
        $canonicalDirectory = realpath($directory);
        $isCanonical        = PHP_OS_FAMILY !== 'Windows'
            && $canonicalDirectory !== false
            && dirname($canonicalDirectory) !== $canonicalDirectory;
        $rootDirectory      = $isCanonical ? $canonicalDirectory : $directory;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($rootDirectory, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file) use ($skipPathMatcher, $isCanonical): bool {
                    if ($file->isLink()) {
                        return false;
                    }

                    if (! $file->isDir()) {
                        if ($file->getExtension() !== 'php') {
                            return false;
                        }

                        try {
                            if ($file->getSize() <= 0) {
                                return false;
                            }
                        } catch (RuntimeException) {
                            return false;
                        }
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
