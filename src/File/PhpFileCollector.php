<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\File;

use Boundwize\StructArmed\Util\Path;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file) use ($skipPathMatcher): bool {
                    if ($file->isLink()) {
                        return false;
                    }

                    if (! $file->isDir() && $file->getExtension() !== 'php') {
                        return false;
                    }

                    return ! $skipPathMatcher->isSkipped($file->getPathname());
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $files[] = Path::normalise($file->getPathname(), canonicalise: true);
        }

        return $files;
    }
}
