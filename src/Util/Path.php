<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util;

use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;

final class Path
{
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

        $normalised = str_replace('\\', '/', $path);
        return strlen($normalised) >= 3 && $normalised[1] === ':' && $normalised[2] === '/';
    }
}
