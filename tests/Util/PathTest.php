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
}
