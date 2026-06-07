<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Tool;

use Boundwize\StructArmed\Tool\Path;
use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Path::class)]
final class PathTest extends TestCase
{
    /**
     * @return Iterator<string, array{string, bool}>
     */
    public static function provideIsAbsolute(): Iterator
    {
        yield 'unix absolute' => ['/path/to/file', true];
        yield 'windows absolute forward' => ['C:/Users/project', true];
        yield 'windows absolute backslash' => ['C:\\Users\\project', true];
        yield 'relative' => ['relative/path', false];
        yield 'windows drive-relative' => ['C:relative', false];
        yield 'dot-relative' => ['./relative', false];
        yield 'windows UNC path' => ['\\\\server\\share\\project', true];
    }

    #[DataProvider('provideIsAbsolute')]
    public function testIsAbsolute(string $path, bool $expected): void
    {
        $this->assertSame($expected, Path::isAbsolute($path));
    }
}
