<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util;

use Boundwize\StructArmed\Util\Path;
use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Path::class)]
final class PathTest extends TestCase
{
    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function provideNormalise(): Iterator
    {
        yield 'relative' => ['src//Domain/', 'src/Domain'];
        yield 'unix absolute' => ['/project//src/', '/project/src'];
        yield 'windows absolute' => ['C:\\project\\src\\', 'C:/project/src'];
        yield 'windows UNC backslashes' => ['\\\\server\\share\\src\\', '//server/share/src'];
        yield 'windows UNC forward slashes' => ['//server/share//src/', '//server/share/src'];
        yield 'unix root' => ['/', '/'];
        yield 'windows drive root forward slash' => ['C:/', 'C:/'];
        yield 'windows drive root backslash' => ['C:\\', 'C:/'];
    }

    #[DataProvider('provideNormalise')]
    public function testNormalise(string $path, string $expected): void
    {
        $this->assertSame($expected, Path::normalise($path));
    }

    /**
     * @return Iterator<string, array{string, string, string}>
     */
    public static function provideResolve(): Iterator
    {
        yield 'relative' => ['src', '/project', '/project/src'];
        yield 'dot-relative' => ['./src', '/project', '/project/./src'];
        yield 'parent-relative' => ['../src', '/project', '/project/../src'];
        yield 'base with trailing slash' => ['src', '/project/', '/project/src'];
        yield 'base with trailing backslash' => ['src', 'C:\\project\\', 'C:\\project/src'];
        yield 'unix absolute' => ['/external/src', '/project', '/external/src'];
        yield 'windows absolute forward' => ['C:/external/src', '/project', 'C:/external/src'];
        yield 'windows absolute' => ['C:\\external\\src', '/project', 'C:\\external\\src'];
        yield 'windows drive-relative' => ['C:src', '/project', '/project/C:src'];
        yield 'windows UNC absolute' => ['\\\\server\\share\\src', '/project', '\\\\server\\share\\src'];
    }

    #[DataProvider('provideResolve')]
    public function testResolve(string $path, string $basePath, string $expected): void
    {
        $this->assertSame($expected, Path::resolve($path, $basePath));
    }

    public function testMemoisesNormalisedAndResolvedPaths(): void
    {
        $path = __DIR__ . '/../Util/PathTest.php';

        $this->assertSame(
            Path::normalise(__FILE__),
            Path::normalise($path, canonicalise: true)
        );
        $this->assertSame(
            Path::normalise($path, canonicalise: true),
            Path::normalise($path, canonicalise: true)
        );
        $this->assertSame(
            Path::resolve('src', '/project'),
            Path::resolve('src', '/project')
        );
    }
}
