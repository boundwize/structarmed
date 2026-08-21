<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use Boundwize\StructArmed\Util\Path;

use function hash;
use function sys_get_temp_dir;

/**
 * @internal
 */
final class CachePathFactory
{
    public static function getPath(?string $cacheDirectory, string $basePath): string
    {
        return $cacheDirectory
            ? Path::resolve(Path::normalise($cacheDirectory), $basePath)
            : Path::normalise(sys_get_temp_dir()) . '/structarmed/cache/' . hash('xxh128', $basePath);
    }
}
