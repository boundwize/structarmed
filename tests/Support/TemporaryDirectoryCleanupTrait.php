<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_reverse;
use function bin2hex;
use function clearstatcache;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const DIRECTORY_SEPARATOR;

trait TemporaryDirectoryCleanupTrait
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $temporaryPath) {
            $this->removeTemporaryPath($temporaryPath);
        }

        $this->temporaryPaths = [];
    }

    protected function makeTemporaryDirectory(string $prefix): string
    {
        $basePath = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($basePath);

        $this->temporaryPaths[] = $basePath;

        return $basePath;
    }

    protected function makeTemporaryFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix . '-');
        $this->assertIsString($path);

        $this->temporaryPaths[] = $path;

        return $path;
    }

    protected function registerTemporaryPath(string $path): string
    {
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function removeTemporaryPath(string $basePath): void
    {
        if (is_file($basePath)) {
            unlink($basePath);

            return;
        }

        if (! is_dir($basePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $pathname = $file->getPathname();
            clearstatcache(true, $pathname);

            if (is_link($pathname)) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    // is_dir() does not reliably follow NTFS symlinks on Windows;
                    // rmdir() removes directory symlinks, unlink() removes file symlinks.
                    @rmdir($pathname) || unlink($pathname);
                } else {
                    unlink($pathname);
                }

                continue;
            }

            if (is_dir($pathname)) {
                rmdir($pathname);

                continue;
            }

            unlink($pathname);
        }

        rmdir($basePath);
    }
}
