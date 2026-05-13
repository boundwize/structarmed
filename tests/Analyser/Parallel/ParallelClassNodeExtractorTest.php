<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\Parallel\ParallelClassNodeExtractor;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function file_put_contents;
use function glob;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;

#[CoversClass(ParallelClassNodeExtractor::class)]
final class ParallelClassNodeExtractorTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testExtractWithEmptyFilesReturnsEmpty(): void
    {
        $extractor = new ParallelClassNodeExtractor(
            basePath:     '/tmp',
            layers:       ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount:  4,
        );

        $result = $extractor->extract([]);

        $this->assertSame([], $result);
    }

    public function testExtractWithWorkerCountOneUsesSequentialPath(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class Foo
{
}
PHP);

        $extractor = new ParallelClassNodeExtractor(
            basePath:      $dir,
            layers:        ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount:   1,
        );

        $result = $extractor->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ClassNode::class, $result[0]);
        $this->assertSame('App\\Domain\\Foo', $result[0]->className);
    }

    public function testExtractWithMultipleFilesUsesParallelPath(): void
    {
        $dir   = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file1 = $dir . '/Foo.php';
        $file2 = $dir . '/Bar.php';

        file_put_contents($file1, <<<'PHP'
<?php

namespace App\Domain;

final class Foo
{
}
PHP);

        file_put_contents($file2, <<<'PHP'
<?php

namespace App\Domain;

final class Bar
{
}
PHP);

        $extractor = new ParallelClassNodeExtractor(
            basePath:      $dir,
            layers:        ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount:   2,
        );

        $result = $extractor->extract([$file1, $file2]);

        $this->assertCount(2, $result);
        $classNames = [$result[0]->className, $result[1]->className];
        $this->assertContains('App\\Domain\\Foo', $classNames);
        $this->assertContains('App\\Domain\\Bar', $classNames);
    }

    public function testExtractWithCacheDirectoryCreatesWorkerTempFilesInIt(): void
    {
        $dir          = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $cacheDir     = $this->makeTemporaryDirectory('structarmed-parallel-cache');
        $file         = $dir . '/Baz.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class Baz
{
}
PHP);

        $extractor = new ParallelClassNodeExtractor(
            basePath:       $dir,
            layers:         ['Domain' => 'App\\Domain'],
            layerPatterns:  [],
            workerCount:    2,
            cacheDirectory: $cacheDir,
        );

        $result = $extractor->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('App\\Domain\\Baz', $result[0]->className);
    }

    public function testExtractWithLayerPatternsUsesChainResolver(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Service.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class FooService
{
}
PHP);

        $extractor = new ParallelClassNodeExtractor(
            basePath:      $dir,
            layers:        ['Domain' => 'App\\Domain'],
            layerPatterns: ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            workerCount:   2,
        );

        $result = $extractor->extract([$file]);

        $this->assertCount(1, $result);
    }

    public function testExtractSequentialPathWithLayerPatterns(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/FooService.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class FooService
{
}
PHP);

        $extractor = new ParallelClassNodeExtractor(
            basePath:      $dir,
            layers:        ['Domain' => 'App\\Domain'],
            layerPatterns: ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            workerCount:   1,
        );

        $result = $extractor->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('App\\Domain\\FooService', $result[0]->className);
    }

    public function testExtractThrowsWhenWorkerFailsDueToNullByteInFilePath(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        // A null byte in a file path causes PHP 8 to throw ValueError in file_get_contents,
        // which is NOT caught by ClassNodeExtractor's catch(PhpParser\Error), so it
        // propagates to ClassNodeWorker's catch(Throwable) → worker exits with code 1
        $fileWithNullByte = $dir . "/foo\x00.php";

        $extractor = new ParallelClassNodeExtractor(
            basePath:      $dir,
            layers:        ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount:   2,
        );

        $this->expectException(RuntimeException::class);
        $extractor->extract([$fileWithNullByte]);
    }

    public function testExtractWithNonExistentCacheDirectoryCreatesIt(): void
    {
        $dir          = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $cacheDir     = sys_get_temp_dir() . '/structarmed-cache-mkdir-' . bin2hex(random_bytes(6));
        $file         = $dir . '/Qux.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class Qux
{
}
PHP);

        $extractor = new ParallelClassNodeExtractor(
            basePath:       $dir,
            layers:         ['Domain' => 'App\\Domain'],
            layerPatterns:  [],
            workerCount:    2,
            cacheDirectory: $cacheDir,
        );

        try {
            $result = $extractor->extract([$file]);
            $this->assertCount(1, $result);
        } finally {
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir . '/*') ?: [] as $tmpFile) {
                    @unlink($tmpFile);
                }

                rmdir($cacheDir);
            }
        }
    }
}
