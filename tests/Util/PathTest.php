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
        yield 'dot-relative' => ['./src', 'src'];
        yield 'dot-relative backslash' => ['.\\src\\', 'src'];
        yield 'repeated dot-relative' => ['././src/Domain', 'src/Domain'];
        yield 'dot slash only' => ['./', '.'];
        yield 'dot only' => ['.', '.'];
        yield 'unix absolute' => ['/project//src/', '/project/src'];
        yield 'windows absolute' => ['C:\\project\\src\\', 'C:/project/src'];
        yield 'windows UNC backslashes' => ['\\\\server\\share\\src\\', '//server/share/src'];
        yield 'windows UNC forward slashes' => ['//server/share//src/', '//server/share/src'];
        yield 'empty' => ['', ''];
        yield 'relative without trailing separator' => ['src/Domain', 'src/Domain'];
        yield 'parent-relative' => ['../shared/src/', '../shared/src'];
        yield 'unix root' => ['/', '/'];
        yield 'unix root backslash' => ['\\', '/'];
        yield 'unix top-level directory' => ['/project/', '/project'];
        yield 'windows drive root forward slash' => ['C:/', 'C:/'];
        yield 'windows drive root backslash' => ['C:\\', 'C:/'];
        yield 'windows drive root repeated separators' => ['C:\\\\', 'C:/'];
        yield 'windows lowercase drive root forward slash' => ['c:/', 'c:/'];
        yield 'windows lowercase drive root backslash' => ['c:\\', 'c:/'];
        yield 'windows drive-relative' => ['C:', 'C:'];
        yield 'windows drive top-level directory' => ['C:/project/', 'C:/project'];
        yield 'windows drive top-level directory backslashes' => ['C:\\project\\', 'C:/project'];
        yield 'numeric drive-like segment' => ['1:/', '1:'];
        yield 'relative drive-like segment' => ['src/C:/', 'src/C:'];
        yield 'windows UNC share root backslashes' => ['\\\\server\\share\\', '//server/share'];
        yield 'windows UNC share root forward slashes' => ['//server/share/', '//server/share'];
        yield 'windows UNC share root without trailing separator' => ['\\\\server\\share', '//server/share'];
        yield 'windows UNC drive-like share' => ['//server/C:/', '//server/C:'];
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
        yield 'windows absolute lowercase drive' => ['c:\\external\\src', '/project', 'c:\\external\\src'];
        yield 'windows drive-relative' => ['C:src', '/project', '/project/C:src'];
        yield 'numeric drive-like segment' => ['1:/src/Foo.php', '/project', '/project/1:/src/Foo.php'];
        yield 'punctuation drive-like segment' => ['@:\\src', '/project', '/project/@:\\src'];
        yield 'windows UNC absolute' => ['\\\\server\\share\\src', '/project', '\\\\server\\share\\src'];
    }

    /**
     * @return Iterator<string, array{string, string, bool}>
     */
    public static function provideIsAnalysableFile(): Iterator
    {
        yield 'relative php' => ['src/Foo.php', '/project', true];
        yield 'absolute php' => ['/project/src/Foo.php', '/project', true];
        yield 'base-relative root composer' => ['composer.json', '/project', true];
        yield 'absolute root composer' => ['/project/composer.json', '/project', true];
        yield 'nested composer' => ['/project/nested/composer.json', '/project', false];
        yield 'other root composer' => ['/other/composer.json', '/project', false];
        yield 'composer lock' => ['/project/composer.lock', '/project', false];
        yield 'readme' => ['/project/README.md', '/project', false];
    }

    #[DataProvider('provideResolve')]
    public function testResolve(string $path, string $basePath, string $expected): void
    {
        $this->assertSame($expected, Path::resolve($path, $basePath));
    }

    #[DataProvider('provideIsAnalysableFile')]
    public function testIsAnalysableFile(string $path, string $basePath, bool $expected): void
    {
        $this->assertSame($expected, Path::isAnalysableFile($path, $basePath));
    }

    /**
     * @return Iterator<string, array{string, string, string}>
     */
    public static function provideRelativeTo(): Iterator
    {
        yield 'inside base' => ['/project/src/Foo.php', '/project', 'src/Foo.php'];
        yield 'base itself' => ['/project', '/project', ''];
        yield 'sibling of base' => ['/shared/src/Foo.php', '/project', '../shared/src/Foo.php'];
        yield 'no common ancestor' => ['C:/other/Foo.php', '/project', 'C:/other/Foo.php'];
        // resolved against the base path, never the working directory
        yield 'relative to base' => ['src/Foo.php', '/project', 'src/Foo.php'];
        yield 'empty is the base' => ['', '/project', ''];
        yield 'inside unix root' => ['/project/src/Foo.php', '/', 'project/src/Foo.php'];
        yield 'relative to unix root' => ['project/Foo.php', '/', 'project/Foo.php'];
        yield 'inside windows drive root' => ['C:/project/src/Foo.php', 'C:/', 'project/src/Foo.php'];
        yield 'windows drive root itself' => ['C:\\', 'C:/', ''];
        yield 'other windows drive' => ['D:/project/Foo.php', 'C:/', 'D:/project/Foo.php'];
    }

    #[DataProvider('provideRelativeTo')]
    public function testRelativeTo(string $path, string $normalisedBasePath, string $expected): void
    {
        $this->assertSame($expected, Path::relativeTo($path, $normalisedBasePath));
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
