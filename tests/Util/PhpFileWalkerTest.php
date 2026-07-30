<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util;

use Boundwize\StructArmed\Util\PhpFileWalker;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function realpath;
use function rmdir;
use function sort;
use function str_ends_with;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(PhpFileWalker::class)]
final class PhpFileWalkerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/structarmed-walker-' . bin2hex(random_bytes(6));

        mkdir($this->directory . '/sub/skipme', recursive: true);

        file_put_contents($this->directory . '/A.php', '<?php');
        file_put_contents($this->directory . '/notes.txt', 'not php');
        file_put_contents($this->directory . '/skipfile.php', '<?php');
        file_put_contents($this->directory . '/sub/B.php', '<?php');
        file_put_contents($this->directory . '/sub/skipme/C.php', '<?php');

        $canonicalDirectory = realpath($this->directory);
        self::assertIsString($canonicalDirectory);
        $this->directory = $canonicalDirectory;
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->directory);
    }

    public function testCollectsPhpFilesRecursivelyIgnoringOtherExtensions(): void
    {
        $files = PhpFileWalker::files($this->directory, static fn(string $file): bool => false);

        sort($files);

        $this->assertSame([
            $this->directory . '/A.php',
            $this->directory . '/skipfile.php',
            $this->directory . '/sub/B.php',
            $this->directory . '/sub/skipme/C.php',
        ], $files);
    }

    public function testSkipsFilesAndPrunesSkippedDirectoriesWithoutDescending(): void
    {
        $checkedPaths = [];
        $isSkipped    = static function (string $file) use (&$checkedPaths): bool {
            $checkedPaths[] = $file;

            return str_ends_with($file, '/skipme') || str_ends_with($file, '/skipfile.php');
        };

        $files = PhpFileWalker::files($this->directory, $isSkipped);

        sort($files);

        $this->assertSame([
            $this->directory . '/A.php',
            $this->directory . '/sub/B.php',
        ], $files);

        // A skipped directory is pruned before descending, so its children are
        // never even offered to the predicate.
        $this->assertNotContains($this->directory . '/sub/skipme/C.php', $checkedPaths);
    }
}
