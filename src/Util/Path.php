<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util;

use function ltrim;
use function preg_replace;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;

final class Path
{
    public static function normalise(string $path): string
    {
        $isUnc = str_starts_with($path, '\\\\') || str_starts_with($path, '//');
        $path  = str_replace('\\', '/', $path);
        $path  = (string) preg_replace('#/+#', '/', $path);

        if ($isUnc) {
            $path = '//' . ltrim($path, '/');
        }

        return rtrim($path, '/');
    }

    public static function resolve(string $path, string $basePath): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return rtrim($basePath, '/\\') . '/' . $path;
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
