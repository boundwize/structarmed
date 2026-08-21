<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Cache;

use Boundwize\StructArmed\Cache\CachePathFactory;
use Boundwize\StructArmed\Util\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function hash;
use function sys_get_temp_dir;

#[CoversClass(CachePathFactory::class)]
final class CachePathFactoryTest extends TestCase
{
    public function testFallsBackToHashedTemporaryDirectoryWhenNoCacheDirectoryGiven(): void
    {
        $basePath = '/path/to/project';

        $this->assertSame(
            Path::normalise(sys_get_temp_dir()) . '/structarmed/cache/' . hash('xxh128', $basePath),
            CachePathFactory::getPath(null, $basePath),
        );
    }

    public function testResolvesRelativeCacheDirectoryAgainstBasePath(): void
    {
        $this->assertSame(
            '/path/to/project/var/cache',
            CachePathFactory::getPath('var/cache', '/path/to/project'),
        );
    }

    public function testKeepsAbsoluteCacheDirectoryAndNormalisesSeparators(): void
    {
        $this->assertSame(
            '/absolute/cache',
            CachePathFactory::getPath('\\absolute\\cache', '/path/to/project'),
        );
    }
}
