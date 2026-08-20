<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util;

use function ltrim;
use function preg_replace;
use function realpath;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

final class Path
{
    /** @var array<string, string> */
    private static array $normalisedPaths = [];

    /** @var array<string, string> */
    private static array $resolvedPaths = [];

    public static function normalise(string $path, bool $canonicalise = false): string
    {
        $cacheKey = ($canonicalise ? "1\0" : "0\0") . $path;

        if (isset(self::$normalisedPaths[$cacheKey])) {
            return self::$normalisedPaths[$cacheKey];
        }

        if ($canonicalise) {
            $path = realpath($path) ?: $path;
        }

        $isUnc = str_starts_with($path, '\\\\') || str_starts_with($path, '//');
        $path  = str_replace('\\', '/', $path);

        if (str_contains($path, '//')) {
            $path = (string) preg_replace('#/+#', '/', $path);
        }

        if ($isUnc) {
            $path = '//' . ltrim($path, '/');
        } elseif (str_starts_with($path, './')) {
            do {
                $path = substr($path, 2);
            } while (str_starts_with($path, './'));

            if ($path === '') {
                $path = '.';
            }
        }

        return self::$normalisedPaths[$cacheKey] = rtrim($path, '/');
    }

    public static function resolve(string $path, string $basePath): string
    {
        $cacheKey = $basePath . "\0" . $path;

        return self::$resolvedPaths[$cacheKey] ?? self::$resolvedPaths[$cacheKey] = self::isAbsolute($path)
            ? $path
            : rtrim($basePath, '/\\') . '/' . $path;
    }

    public static function isAnalysableFile(string $path, string $basePath): bool
    {
        if (str_ends_with($path, '.php')) {
            return true;
        }

        if (! str_ends_with($path, 'composer.json')) {
            return false;
        }

        $resolvedPath = self::resolve($path, $basePath);

        return self::normalise($resolvedPath, canonicalise: true) === self::normalise(
            self::resolve('composer.json', $basePath),
            canonicalise: true,
        );
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || (strlen($path) >= 3
                && $path[1] === ':'
                && ($path[2] === '/' || $path[2] === '\\'));
    }
}
