<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Rule\Rules\File\PhpFileFinder;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use Boundwize\StructArmed\Util\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;

#[CoversClass(PhpFileFinder::class)]
final class PhpFileFinderTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testFiltersScopeBySourcePathBoundaryAndSkipPathsWithoutDuplicates(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-php-file-finder');
        mkdir($basePath . '/src/Nested', 0777, true);
        mkdir($basePath . '/src-old');
        mkdir($basePath . '/tests');

        foreach (
            [
                '/src/Foo.php',
                '/src/Nested/Bar.php',
                '/src-old/Baz.php',
                '/tests/FooTest.php',
                '/src/composer.json',
            ] as $file
        ) {
            file_put_contents($basePath . $file, '<?php');
        }

        $scopeFiles = [
            $basePath . '/src/Foo.php',
            $basePath . '/src/Nested/Bar.php',
            $basePath . '/src-old/Baz.php',
            $basePath . '/tests/FooTest.php',
            $basePath . '/src/composer.json',
        ];
        $filtered   = (new PhpFileFinder(['src/', 'src/Nested/']))->files(
            $basePath,
            ['src/Nested/'],
            $scopeFiles,
        );

        $this->assertSame([Path::normalise($basePath . '/src/Foo.php', canonicalise: true)], $filtered);
    }

    public function testUsesComposerPsr4PathsToFilterScopeByDefault(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-php-file-finder');
        mkdir($basePath . '/src');
        mkdir($basePath . '/tests');

        file_put_contents($basePath . '/composer.json', <<<'JSON'
            {
                "autoload": {"psr-4": {"App\\": "src/"}},
                "autoload-dev": {"psr-4": {"App\\Tests\\": "tests/"}}
            }
            JSON);
        file_put_contents($basePath . '/src/Foo.php', '<?php');
        file_put_contents($basePath . '/tests/FooTest.php', '<?php');
        file_put_contents($basePath . '/Outside.php', '<?php');

        $filtered = (new PhpFileFinder())->files(
            $basePath,
            [],
            [
                $basePath . '/src/Foo.php',
                $basePath . '/tests/FooTest.php',
                $basePath . '/Outside.php',
            ],
        );

        $this->assertSame(
            [
                Path::normalise($basePath . '/src/Foo.php', canonicalise: true),
                Path::normalise($basePath . '/tests/FooTest.php', canonicalise: true),
            ],
            $filtered,
        );
    }

    public function testEachFinderFiltersScopeUsingItsOwnSourcePaths(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-php-file-finder');
        mkdir($basePath . '/src');
        mkdir($basePath . '/custom');

        $sourceFile = $basePath . '/src/Foo.php';
        $customFile = $basePath . '/custom/Bar.php';
        file_put_contents($sourceFile, '<?php final class Foo {}');
        file_put_contents($customFile, '<?php final class Bar {}');

        $scopeFiles = [$sourceFile, $customFile];

        $this->assertSame(
            [Path::normalise($sourceFile, canonicalise: true)],
            (new PhpFileFinder(['src/']))->files($basePath, [], $scopeFiles),
        );
        $this->assertSame(
            [Path::normalise($customFile, canonicalise: true)],
            (new PhpFileFinder(['custom/']))->files($basePath, [], $scopeFiles),
        );
    }
}
