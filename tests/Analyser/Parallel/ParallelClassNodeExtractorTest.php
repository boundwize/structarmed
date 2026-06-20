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
use function unlink;

#[CoversClass(ParallelClassNodeExtractor::class)]
final class ParallelClassNodeExtractorTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testExtractWithEmptyFilesReturnsEmpty(): void
    {
        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: '/tmp',
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 4,
        );

        $extractionResult = $parallelClassNodeExtractor->extract([]);

        $this->assertSame([], $extractionResult->classNodes);
        $this->assertSame([], $extractionResult->fileAnalyses);
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

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 1,
        );

        $extractionResult = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertInstanceOf(ClassNode::class, $extractionResult->classNodes[0]);
        $this->assertSame('App\\Domain\\Foo', $extractionResult->classNodes[0]->className);
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

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
        );

        $extractionResult = $parallelClassNodeExtractor->extract([$file1, $file2]);

        $this->assertCount(2, $extractionResult->classNodes);
        $classNames = [$extractionResult->classNodes[0]->className, $extractionResult->classNodes[1]->className];
        $this->assertContains('App\\Domain\\Foo', $classNames);
        $this->assertContains('App\\Domain\\Bar', $classNames);
    }

    public function testExtractReturnsWorkerFacts(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';

        file_put_contents($file, '<?php final class Foo {} echo "side effect";');

        $extractionResult = (new ParallelClassNodeExtractor($dir, ['Source' => ''], [], 2))
            ->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertArrayHasKey($file, $extractionResult->fileAnalyses);
        $this->assertTrue($extractionResult->fileAnalyses[$file]->declaresSymbols);
        $this->assertTrue($extractionResult->fileAnalyses[$file]->hasSideEffects);
    }

    public function testExtractWithCacheDirectoryCreatesWorkerTempFilesInIt(): void
    {
        $dir      = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $cacheDir = $this->makeTemporaryDirectory('structarmed-parallel-cache');
        $file     = $dir . '/Baz.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class Baz
{
}
PHP);

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
            cacheDirectory: $cacheDir,
        );

        $extractionResult = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertSame('App\\Domain\\Baz', $extractionResult->classNodes[0]->className);
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

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            workerCount: 2,
        );

        $extractionResult = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
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

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            workerCount: 1,
        );

        $extractionResult = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertSame('App\\Domain\\FooService', $extractionResult->classNodes[0]->className);
    }

    public function testExtractThrowsWhenWorkerFailsDueToNullByteInFilePath(): void
    {
        $dir = $this->makeTemporaryDirectory('structarmed-parallel-test');
        // A null byte in a file path causes PHP 8 to throw ValueError in file_get_contents,
        // which is NOT caught by ClassNodeExtractor's catch(PhpParser\Error), so it
        // propagates to ClassNodeWorker's catch(Throwable) → worker exits with code 1
        $fileWithNullByte = $dir . "/foo\x00.php";

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
        );

        $this->expectException(RuntimeException::class);
        $parallelClassNodeExtractor->extract([$fileWithNullByte]);
    }

    public function testExtractWithNonExistentCacheDirectoryCreatesIt(): void
    {
        $dir      = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $cacheDir = sys_get_temp_dir() . '/structarmed-cache-mkdir-' . bin2hex(random_bytes(6));
        $file     = $dir . '/Qux.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class Qux
{
}
PHP);

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
            cacheDirectory: $cacheDir,
        );

        try {
            $result = $parallelClassNodeExtractor->extract([$file]);
            $this->assertCount(1, $result->classNodes);
        } finally {
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir . '/*') ?: [] as $tmpFile) {
                    @unlink($tmpFile);
                }

                rmdir($cacheDir);
            }
        }
    }

    public function testExtractThrowsWhenProcOpenFails(): void
    {
        $GLOBALS['mock_proc_open'] = true;

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to start parallel analysis worker.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_proc_open'] = false;
        }
    }

    public function testExtractThrowsWhenTempnamFails(): void
    {
        $GLOBALS['mock_tempnam'] = true;

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create temporary file for parallel analysis.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_tempnam'] = false;
        }
    }

    public function testExtractThrowsWhenPayloadIsInvalid(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = ['invalid' => 'payload'];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned an invalid payload.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    public function testExtractThrowsWhenErrorPayloadIsInvalid(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = ['nodes' => [], 'error' => ['not_a_string']];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned an invalid error payload.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    public function testExtractThrowsWhenFileAnalysesPayloadIsNotAnArray(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = [
            'nodes'        => [],
            'fileAnalyses' => 'invalid',
            'error'        => null,
        ];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned invalid file analyses.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    public function testExtractThrowsWhenFileAnalysisEntryIsInvalid(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = [
            'nodes'        => [],
            'fileAnalyses' => ['Foo.php' => 'invalid'],
            'error'        => null,
        ];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned invalid file analyses.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }
}
