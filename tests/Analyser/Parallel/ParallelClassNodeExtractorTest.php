<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

// phpcs:disable
require_once __DIR__ . '/MockFunctions.php';
// phpcs:enable

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\Parallel\ClassNodeWorker;
use Boundwize\StructArmed\Analyser\Parallel\ParallelClassNodeExtractor;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function file_put_contents;
use function serialize;

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

        $result = $parallelClassNodeExtractor->extract([]);

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

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 1,
        );

        $result = $parallelClassNodeExtractor->extract($this->analysisFiles($file));

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

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
        );

        $result = $parallelClassNodeExtractor->extract($this->analysisFiles($file1, $file2));

        $this->assertCount(2, $result);
        $classNames = [$result[0]->className, $result[1]->className];
        $this->assertContains('App\\Domain\\Foo', $classNames);
        $this->assertContains('App\\Domain\\Bar', $classNames);
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

        $result = $parallelClassNodeExtractor->extract($this->analysisFiles($file));

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

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            workerCount: 1,
        );

        $result = $parallelClassNodeExtractor->extract($this->analysisFiles($file));

        $this->assertCount(1, $result);
        $this->assertSame('App\\Domain\\FooService', $result[0]->className);
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
        $parallelClassNodeExtractor->extract($this->analysisFiles($fileWithNullByte));
    }

    public function testExtractThrowsWhenProcOpenFails(): void
    {
        $GLOBALS['mock_proc_open'] = true;

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 4);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to start parallel analysis worker.');

        try {
            $parallelClassNodeExtractor->extract($this->analysisFiles($file));
        } finally {
            $GLOBALS['mock_proc_open'] = false;
        }
    }

    public function testExtractCleansUpStartedWorkersWhenLaterWorkerFailsToStart(): void
    {
        $dir   = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file1 = $dir . '/Foo.php';
        $file2 = $dir . '/Bar.php';
        file_put_contents($file1, '<?php class Foo {}');
        file_put_contents($file2, '<?php class Bar {}');

        $GLOBALS['mock_proc_open']       = [
            'failOnCall'    => 2,
            'resultPayload' => serialize([
                'nodes' => [],
                'error' => null,
            ]),
        ];
        $GLOBALS['mock_proc_open_calls'] = 0;

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 4);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to start parallel analysis worker.');

        try {
            $parallelClassNodeExtractor->extract($this->analysisFiles($file1, $file2));
        } finally {
            $GLOBALS['mock_proc_open']       = false;
            $GLOBALS['mock_proc_open_calls'] = 0;
        }
    }

    public function testExtractDistributesLargeInputAcrossWorkers(): void
    {
        $dir   = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $files = [];

        for ($i = 0; $i < 300; $i++) {
            $files[] = ['file' => $dir . '/File' . $i . '.php', 'size' => 1];
        }

        $GLOBALS['mock_proc_open']       = [
            'resultPayload' => serialize([
                'nodes' => [],
                'error' => null,
            ]),
        ];
        $GLOBALS['mock_proc_open_calls'] = 0;

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 4);

        try {
            $result = $parallelClassNodeExtractor->extract($files);
        } finally {
            $procOpenCalls                   = $GLOBALS['mock_proc_open_calls'];
            $GLOBALS['mock_proc_open']       = false;
            $GLOBALS['mock_proc_open_calls'] = 0;
        }

        $this->assertSame([], $result);
        $this->assertSame(4, $procOpenCalls);
    }

    public function testExtractAdvancesProgressFromWorkerMarkers(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $GLOBALS['mock_proc_open'] = [
            'progressPayload' => ClassNodeWorker::PROGRESS_MARKER . ClassNodeWorker::PROGRESS_MARKER,
            'resultPayload'   => serialize([
                'nodes' => [],
                'error' => null,
            ]),
        ];

        $advancedFiles = [];
        $progress      = new class ($advancedFiles) implements ProgressHandlerInterface {
            /** @param list<string> $advancedFiles */
            public function __construct(
                /** @phpstan-ignore property.onlyWritten */
                private array &$advancedFiles
            ) {
            }

            public function start(int $total): void
            {
            }

            public function advance(string $file): void
            {
                $this->advancedFiles[] = $file;
            }

            public function finish(): void
            {
            }
        };

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        try {
            $result = $parallelClassNodeExtractor->extract($this->analysisFiles($file), $progress);
        } finally {
            $GLOBALS['mock_proc_open'] = false;
        }

        $this->assertSame([], $result);
        $this->assertSame([$file], $advancedFiles);
    }

    public function testExtractAdvancesProgressFromWorkerMarkerFile(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $GLOBALS['mock_proc_open'] = [
            'progressPayload' => ClassNodeWorker::PROGRESS_MARKER,
            'resultPayload'   => serialize([
                'nodes' => [],
                'error' => null,
            ]),
        ];

        $advancedFiles = [];
        $progress      = new class ($advancedFiles) implements ProgressHandlerInterface {
            /** @param list<string> $advancedFiles */
            public function __construct(
                /** @phpstan-ignore property.onlyWritten */
                private array &$advancedFiles
            ) {
            }

            public function start(int $total): void
            {
            }

            public function advance(string $file): void
            {
                $this->advancedFiles[] = $file;
            }

            public function finish(): void
            {
            }
        };

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2, false);

        try {
            $result = $parallelClassNodeExtractor->extract($this->analysisFiles($file), $progress);
        } finally {
            $GLOBALS['mock_proc_open'] = false;
        }

        $this->assertSame([], $result);
        $this->assertSame([$file], $advancedFiles);
    }

    public function testExtractCreatesMissingTemporaryDirectoryBeforeWorkerFiles(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $GLOBALS['mock_is_dir']      = false;
        $GLOBALS['mock_mkdir_calls'] = 0;
        $GLOBALS['mock_proc_open']   = [
            'resultPayload' => serialize([
                'nodes' => [],
                'error' => null,
            ]),
        ];

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        try {
            $result = $parallelClassNodeExtractor->extract($this->analysisFiles($file));
        } finally {
            $mkdirCalls                  = $GLOBALS['mock_mkdir_calls'];
            $GLOBALS['mock_is_dir']      = null;
            $GLOBALS['mock_mkdir_calls'] = 0;
            $GLOBALS['mock_proc_open']   = false;
        }

        $this->assertSame([], $result);
        $this->assertSame(3, $mkdirCalls);
    }

    public function testExtractThrowsWhenTemporaryFileCannotBeCreated(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $GLOBALS['mock_tempnam'] = false;

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create temporary file for parallel analysis.');

        try {
            $parallelClassNodeExtractor->extract($this->analysisFiles($file));
        } finally {
            $GLOBALS['mock_tempnam'] = null;
        }
    }

    public function testExtractThrowsWhenWorkerReturnsInvalidPayload(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $GLOBALS['mock_proc_open'] = [
            'resultPayload' => serialize([
                'error' => null,
            ]),
        ];

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned an invalid payload.');

        try {
            $parallelClassNodeExtractor->extract($this->analysisFiles($file));
        } finally {
            $GLOBALS['mock_proc_open'] = false;
        }
    }

    public function testExtractThrowsWhenWorkerReturnsInvalidErrorPayload(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $GLOBALS['mock_proc_open'] = [
            'resultPayload' => serialize([
                'nodes' => [],
                'error' => ['invalid'],
            ]),
        ];

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned an invalid error payload.');

        try {
            $parallelClassNodeExtractor->extract($this->analysisFiles($file));
        } finally {
            $GLOBALS['mock_proc_open'] = false;
        }
    }

    public function testExtractIncludesWorkerStderrWhenWorkerReturnsInvalidPayload(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $GLOBALS['mock_proc_open'] = [
            'resultPayload' => '',
            'stderrPayload' => 'worker failed before writing a result',
        ];

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('worker failed before writing a result');

        try {
            $parallelClassNodeExtractor->extract($this->analysisFiles($file));
        } finally {
            $GLOBALS['mock_proc_open'] = false;
        }
    }

    /**
     * @return list<array{file: string, size: int}>
     */
    private function analysisFiles(string ...$files): array
    {
        $analysisFiles = [];

        foreach ($files as $file) {
            $analysisFiles[] = ['file' => $file, 'size' => 1];
        }

        return $analysisFiles;
    }
}
