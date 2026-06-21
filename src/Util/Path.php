<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util;

use function ltrim;
use function preg_replace;
use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;

final class Path
{
    /** @var array<string, string> */
    private static array $normalisedPaths = [];

    /** @var array<string, string> */
    private static array $resolvedPaths = [];

    public static function normalise(string $path, bool $canonicalise = false): string
    {
        if ($canonicalise) {
            $cacheKey = "1\0" . $path;

            if (isset(self::$normalisedPaths[$cacheKey])) {
                return self::$normalisedPaths[$cacheKey];
            }

            $canonicalPath = realpath($path);

            if ($canonicalPath === false) {
                return self::$normalisedPaths[$cacheKey] = self::normalise($path);
            }

            return self::$normalisedPaths[$cacheKey] = self::normalise($canonicalPath);
        }

        $cacheKey = "0\0" . $path;

        if (isset(self::$normalisedPaths[$cacheKey])) {
            return self::$normalisedPaths[$cacheKey];
        }

        $isUnc = str_starts_with($path, '\\\\') || str_starts_with($path, '//');
        $path  = str_replace('\\', '/', $path);
        $path  = (string) preg_replace('#/+#', '/', $path);

        if ($isUnc) {
            $path = '//' . ltrim($path, '/');
        }

        $normalised = rtrim($path, '/');

        // Preserve Unix root '/' and Windows drive roots like 'C:/' — rtrim would reduce them to '' or 'C:'
        return self::$normalisedPaths[$cacheKey] = (
            $normalised === '' || (strlen($normalised) === 2 && $normalised[1] === ':')
        ) ? $path : $normalised;
    }

    public static function resolve(string $path, string $basePath): string
    {
        $cacheKey = $basePath . "\0" . $path;

        return self::$resolvedPaths[$cacheKey] ?? self::$resolvedPaths[$cacheKey] = self::isAbsolute($path)
            ? $path
            : rtrim($basePath, '/\\') . '/' . $path;
    }

    private static function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
            return true;
        }

        return strlen($path) >= 3
            && $path[1] === ':'
            && ($path[2] === '/' || $path[2] === '\\');
    }
}
