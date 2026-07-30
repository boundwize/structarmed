<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Recursively collects .php files under a directory, pruning skipped
 * directories before descending into them. Skip-path semantics stay with the
 * caller via the predicate; only the traversal lives here.
 */
final class PhpFileWalker
{
    /**
     * @param callable(string): bool $isSkipped Receives an entry's pathname; a
     *                                          skipped directory is not descended into.
     * @return list<string>
     */
    public static function files(string $directory, callable $isSkipped): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file) use ($isSkipped): bool {
                    $isRealDirectory = $file->isDir() && ! $file->isLink();
                    if (! $isRealDirectory && $file->getExtension() !== 'php') {
                        return false;
                    }

                    return ! $isSkipped($file->getRealPath());
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $files[] = $file->getRealPath();
        }

        return $files;
    }
}
