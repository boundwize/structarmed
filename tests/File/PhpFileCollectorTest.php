<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\File;

use Boundwize\StructArmed\File\PhpFileCollector;
use Boundwize\StructArmed\File\SkipPathMatcher;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use Boundwize\StructArmed\Util\Path;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function realpath;
use function sort;
use function sprintf;
use function str_replace;
use function symlink;

use const PHP_OS_FAMILY;

#[CoversClass(PhpFileCollector::class)]
final class PhpFileCollectorTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    /**
     * Every root alias and skip path form yields the same files, in the same order,
     * as canonicalising each discovered file with realpath().
     */
    public function testCollectsSameFilesAsCanonicalisingEveryFile(): void
    {
        // sys_get_temp_dir() is itself a symlink on macOS (/var -> /private/var)
        $basePath = $this->makeTemporaryDirectory('structarmed-collector');
        foreach (
            [
                'src/A.php',
                'src/notes.txt',
                'src/Sub/B.php',
                'src/Skipped/C.php',
                'src/Gen/Nested/D.php',
                'src-extra/E.php',
                'outside/F.php',
            ] as $file
        ) {
            $this->createFile($basePath . '/' . $file);
        }

        symlink($basePath . '/outside', $basePath . '/src/LinkedDirectory');
        symlink($basePath . '/outside/F.php', $basePath . '/src/Linked.php');

        $canonicalBasePath = realpath($basePath);
        $this->assertIsString($canonicalBasePath);

        $roots = [
            $basePath . '/src',
            $basePath . '/src/',
            $basePath . '/src-extra/../src',
            $basePath . '/./src',
            $canonicalBasePath . '/src',
        ];

        if (PHP_OS_FAMILY === 'Windows') {
            // isLink() is false for a junction, so the walk descends into it like a plain
            // directory; registered last, it is removed first, before its target
            $junction = $this->registerTemporaryPath($basePath . '/src-extra/Junction');
            exec(sprintf(
                'mklink /J %s %s',
                escapeshellarg(str_replace('/', '\\', $junction)),
                escapeshellarg(str_replace('/', '\\', $basePath . '/outside'))
            ));
            $this->assertDirectoryExists($junction);
            $roots[] = $basePath . '/src-extra';
        } else {
            symlink($basePath . '/src', $basePath . '/src-link');
            $roots[] = $basePath . '/src-link';
        }

        $skipPathSets = [
            [],
            ['src/Skipped'],
            ['src/Skipped/C.php'],
            [$canonicalBasePath . '/src/Skipped/'],
            ['src/*/Nested', '*.txt'],
            ['src'],
        ];

        $phpFileCollector = new PhpFileCollector();

        foreach ($roots as $root) {
            foreach ($skipPathSets as $skipPaths) {
                $collected = $phpFileCollector->collect($root, SkipPathMatcher::compile($basePath, $skipPaths));

                // A differently spelled base path compiles a separate matcher, so neither
                // side reads skip decisions the other memoised.
                $this->assertSame(
                    $this->collectCanonicalisingEveryFile($root, SkipPathMatcher::compile($basePath . '/', $skipPaths)),
                    $collected,
                    $root . ' skipping ' . implode(', ', $skipPaths)
                );
                $this->assertSame(
                    $collected,
                    $phpFileCollector->collect($root, SkipPathMatcher::compile($basePath, $skipPaths))
                );
            }
        }

        $collected = $phpFileCollector->collect(
            $basePath . '/src-extra/../src',
            SkipPathMatcher::compile($basePath, ['src/Skipped'])
        );
        sort($collected);

        $this->assertSame(
            [
                Path::normalise($canonicalBasePath . '/src/A.php'),
                Path::normalise($canonicalBasePath . '/src/Gen/Nested/D.php'),
                Path::normalise($canonicalBasePath . '/src/Sub/B.php'),
            ],
            $collected
        );
    }

    /**
     * The collector as it was before skipping per-file realpath().
     *
     * @return list<string>
     */
    private function collectCanonicalisingEveryFile(string $directory, SkipPathMatcher $skipPathMatcher): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => ! $file->isLink()
                    && ($file->isDir() || $file->getExtension() === 'php')
                    && ! $skipPathMatcher->isSkipped($file->getPathname())
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $files[] = Path::normalise($file->getPathname(), canonicalise: true);
        }

        return $files;
    }

    private function createFile(string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, '<?php');
    }
}
