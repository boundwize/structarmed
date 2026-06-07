<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tool;

use function str_replace;
use function str_starts_with;
use function strlen;

final class Path
{
    public static function isAbsolute(string $path): bool
    {
        $normalised = str_replace('\\', '/', $path);

        if (str_starts_with($normalised, '/')) {
            return true;
        }

        return strlen($normalised) >= 3 && $normalised[1] === ':' && $normalised[2] === '/';
    }
}
