<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_reverse;
use function bin2hex;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

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
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());

                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($basePath);
    }
}
