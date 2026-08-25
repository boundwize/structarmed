<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Rule\Rules\File\PhpFileFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(PhpFileFinder::class)]
final class PhpFileFinderTest extends TestCase
{
    public function testMemoisesResolvedFilesAcrossInstances(): void
    {
        $basePath = $this->makeTempDir();

        try {
            mkdir($basePath . '/src');
            file_put_contents($basePath . '/src/First.php', '<?php');

            $files = (new PhpFileFinder(['src/']))->files($basePath);

            file_put_contents($basePath . '/src/AddedAfterScan.php', '<?php');

            $this->assertSame($files, (new PhpFileFinder(['src/']))->files($basePath));
        } finally {
            unlink($basePath . '/src/First.php');
            unlink($basePath . '/src/AddedAfterScan.php');
            rmdir($basePath . '/src');
            rmdir($basePath);
        }
    }

    public function testMemoisedFilesAreScopedByMatcherSourcePathsAndBasePath(): void
    {
        $firstBasePath  = $this->makeTempDir();
        $secondBasePath = $this->makeTempDir();

        try {
            mkdir($firstBasePath . '/src');
            mkdir($firstBasePath . '/tests');
            mkdir($secondBasePath . '/src');
            file_put_contents($firstBasePath . '/src/Source.php', '<?php');
            file_put_contents($firstBasePath . '/tests/SourceTest.php', '<?php');
            file_put_contents($secondBasePath . '/src/Other.php', '<?php');

            $sourceFiles    = (new PhpFileFinder(['src/']))->files($firstBasePath);
            $testFiles      = (new PhpFileFinder(['tests/']))->files($firstBasePath);
            $otherBaseFiles = (new PhpFileFinder(['src/']))->files($secondBasePath);
            $skippedFiles   = (new PhpFileFinder(['src/']))->files($firstBasePath, ['src/']);

            $this->assertSame([(string) realpath($firstBasePath . '/src/Source.php')], $sourceFiles);
            $this->assertSame([(string) realpath($firstBasePath . '/tests/SourceTest.php')], $testFiles);
            $this->assertSame([(string) realpath($secondBasePath . '/src/Other.php')], $otherBaseFiles);
            $this->assertSame([], $skippedFiles);
        } finally {
            unlink($firstBasePath . '/src/Source.php');
            unlink($firstBasePath . '/tests/SourceTest.php');
            unlink($secondBasePath . '/src/Other.php');
            rmdir($firstBasePath . '/src');
            rmdir($firstBasePath . '/tests');
            rmdir($secondBasePath . '/src');
            rmdir($firstBasePath);
            rmdir($secondBasePath);
        }
    }

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir() . '/structarmed-php-file-finder-' . bin2hex(random_bytes(6));
        mkdir($path);

        return $path;
    }
}
