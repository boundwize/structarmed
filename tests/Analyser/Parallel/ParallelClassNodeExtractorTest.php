<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\Parallel\ParallelClassNodeExtractor;
use Boundwize\StructArmed\Analyser\Parallel\ValueObject\WorkerProcessState;
use Boundwize\StructArmed\Analyser\Parallel\WorkerPayloadSocket;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function assert;
use function fclose;
use function file_put_contents;
use function fopen;
use function fwrite;
use function is_resource;
use function proc_open;
use function rewind;

use const PHP_BINARY;

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

        $result = $parallelClassNodeExtractor->extract([$file]);

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

        $result = $parallelClassNodeExtractor->extract([$file1, $file2]);

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

        $result = $parallelClassNodeExtractor->extract([$file]);

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

        $result = $parallelClassNodeExtractor->extract([$file]);

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
        $parallelClassNodeExtractor->extract([$fileWithNullByte]);
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

    public function testExtractContinuesAfterPartialProgressRead(): void
    {
        $script = $this->createWorkerScript(<<<'PHP'
<?php
fwrite(STDERR, "\n");
fflush(STDERR);
usleep(200000);
$data = serialize(['nodes' => [], 'error' => null]);
fwrite(STDOUT, pack('N', strlen($data)) . $data);
fflush(STDOUT);
PHP);

        $progress = new class implements ProgressHandlerInterface {
            /** @var list<string> */
            public array $files = [];

            public function start(int $total): void
            {
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
            }
        };

        $GLOBALS['mock_proc_open_command'] = [PHP_BINARY, $script];

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 1);

            $result = $parallelClassNodeExtractor->extract(['/tmp/Foo.php'], $progress);

            $this->assertSame([], $result);
            $this->assertSame(['/tmp/Foo.php'], $progress->files);
        } finally {
            $GLOBALS['mock_proc_open_command'] = null;
        }
    }

    public function testExtractThrowsResultFailureMessageWhenWorkerHasNoStderr(): void
    {
        $script = $this->createWorkerScript(<<<'PHP'
<?php
exit(0);
PHP);

        $GLOBALS['mock_proc_open_command'] = [PHP_BINARY, $script];

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to read worker payload.');

            $parallelClassNodeExtractor->extract(['/tmp/Foo.php']);
        } finally {
            $GLOBALS['mock_proc_open_command'] = null;
        }
    }

    public function testExtractThrowsUnknownErrorWhenWorkerResultCannotBeReadAndStderrExists(): void
    {
        $script = $this->createWorkerScript(<<<'PHP'
<?php
fwrite(STDERR, "worker stderr");
fflush(STDERR);
exit(1);
PHP);

        $GLOBALS['mock_proc_open_command'] = [PHP_BINARY, $script];

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("Parallel analysis worker failed: unknown error\nworker stderr");

            $parallelClassNodeExtractor->extract(['/tmp/Foo.php']);
        } finally {
            $GLOBALS['mock_proc_open_command'] = null;
        }
    }

    public function testExtractThrowsWhenWorkerHasNoReadableResultPipe(): void
    {
        $script = $this->createWorkerScript(<<<'PHP'
<?php
exit(0);
PHP);

        /**
         * @param array<int, list<string>|resource> $descriptorSpec
         */
        $GLOBALS['mock_proc_open_callback'] = static function (
            array|string $command,
            array $descriptorSpec,
            array|null &$pipes
        ) use ($script): mixed {
            $process = proc_open([PHP_BINARY, $script], $descriptorSpec, $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start mocked process.');
            }

            assert(isset($pipes[1]));

            fclose($pipes[1]);

            return $process;
        };

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Parallel analysis worker returned an invalid payload.');

            $parallelClassNodeExtractor->extract(['/tmp/Foo.php']);
        } finally {
            $GLOBALS['mock_proc_open_callback'] = null;
        }
    }

    public function testExtractFinalizesAfterReadableProgressPipeWithoutEof(): void
    {
        $validResultPayload = fopen('php://temp', 'w+');
        $progressPipe       = fopen('php://temp', 'w+');

        assert($validResultPayload !== false);
        assert($progressPipe !== false);

        WorkerPayloadSocket::writePayload($validResultPayload, ['nodes' => [], 'error' => null]);
        rewind($validResultPayload);
        file_put_contents('php://temp', '');
        fwrite($progressPipe, "\n");
        rewind($progressPipe);

        /**
         *  @param array<int, list<string>|resource> $descriptorSpec
         */
        $GLOBALS['mock_proc_open_callback'] = static function (
            array|string $command,
            array $descriptorSpec,
            array|null &$pipes
        ) use (
            $validResultPayload,
            $progressPipe
        ): mixed {
            $process = proc_open([PHP_BINARY, '-r', 'exit(0);'], $descriptorSpec, $realPipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start mocked process.');
            }

            foreach ($realPipes as $realPipe) {
                if (is_resource($realPipe)) {
                    fclose($realPipe);
                }
            }

            $payloadPipe = fopen('php://temp', 'w+');
            assert($payloadPipe !== false);

            $pipes = [
                0 => $payloadPipe,
                1 => $validResultPayload,
                2 => $progressPipe,
            ];

            return $process;
        };

        $progress = new class implements ProgressHandlerInterface {
            /** @var list<string> */
            public array $files = [];

            public function start(int $total): void
            {
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
            }
        };

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 1);

            $result = $parallelClassNodeExtractor->extract(['/tmp/Foo.php'], $progress);

            $this->assertSame([], $result);
            $this->assertSame(['/tmp/Foo.php'], $progress->files);
        } finally {
            $GLOBALS['mock_proc_open_callback'] = null;
        }
    }

    public function testExtractThrowsWhenWorkerNeverProvidesAResultPayload(): void
    {
        $progressPipe = fopen('php://temp', 'w+');

        assert($progressPipe !== false);

        fwrite($progressPipe, "\n");
        rewind($progressPipe);

        /**
         * @param array<int, list<string>|resource> $descriptorSpec
         */
        $GLOBALS['mock_proc_open_callback'] = static function (
            array|string $command,
            array $descriptorSpec,
            array|null &$pipes
        ) use ($progressPipe): mixed {
            $process = proc_open([PHP_BINARY, '-r', 'exit(0);'], $descriptorSpec, $realPipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start mocked process.');
            }

            foreach ($realPipes as $realPipe) {
                if (is_resource($realPipe)) {
                    fclose($realPipe);
                }
            }

            $payloadPipe = fopen('php://temp', 'w+');
            $resultPipe  = fopen('php://temp', 'w+');
            assert($payloadPipe !== false);
            assert($resultPipe !== false);
            fclose($resultPipe);

            $pipes = [
                0 => $payloadPipe,
                1 => $resultPipe,
                2 => $progressPipe,
            ];

            return $process;
        };

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Parallel analysis worker returned an invalid payload.');

            $parallelClassNodeExtractor->extract(['/tmp/Foo.php']);
        } finally {
            $GLOBALS['mock_proc_open_callback'] = null;
        }
    }

    public function testDetermineBatchSizeReturnsExpectedSizes(): void
    {
        $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 4);

        $determineBatchSize = Closure::bind(
            fn (int $totalFiles, int $workerCount): int => $this->determineBatchSize($totalFiles, $workerCount),
            $parallelClassNodeExtractor,
            ParallelClassNodeExtractor::class,
        );

        $this->assertSame(10, $determineBatchSize(10, 1));
        $this->assertSame(5, $determineBatchSize(10, 2));
    }

    public function testCollectReadableStreamsSkipsCompletedResultPipes(): void
    {
        $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 4);
        $stderrPipe                 = fopen('php://temp', 'w+');
        $resultPipe                 = fopen('php://temp', 'w+');
        $doneResultPipe             = fopen('php://temp', 'w+');
        $failedResultPipe           = fopen('php://temp', 'w+');

        assert($stderrPipe !== false);
        assert($resultPipe !== false);
        assert($doneResultPipe !== false);
        assert($failedResultPipe !== false);

        $doneProcess    = fopen('php://temp', 'w+');
        $doneStderrPipe = fopen('php://temp', 'w+');
        $failedProcess  = fopen('php://temp', 'w+');
        $failedStderr   = fopen('php://temp', 'w+');
        $pendingProcess = fopen('php://temp', 'w+');

        assert($doneProcess !== false);
        assert($doneStderrPipe !== false);
        assert($failedProcess !== false);
        assert($failedStderr !== false);
        assert($pendingProcess !== false);

        $doneWorker         = new WorkerProcessState(
            'done',
            $doneProcess,
            ['/tmp/B.php'],
            $doneStderrPipe,
            $doneResultPipe
        );
        $doneWorker->result = ['nodes' => [], 'error' => null];

        $failedWorker                = new WorkerProcessState(
            'failed',
            $failedProcess,
            ['/tmp/C.php'],
            $failedStderr,
            $failedResultPipe
        );
        $failedWorker->resultFailure = new RuntimeException('failed');

        $pendingWorker = new WorkerProcessState(
            'pending',
            $pendingProcess,
            ['/tmp/A.php'],
            $stderrPipe,
            $resultPipe
        );

        /** @var array<int, WorkerProcessState> $processes */
        $processes = [
            $pendingWorker,
            $doneWorker,
            $failedWorker,
        ];

        $reflectionMethod = new ReflectionMethod(
            ParallelClassNodeExtractor::class,
            'collectReadableStreams'
        );

        /** @var array{streams: list<resource>, meta: array<int, array{key: int, type: 'stderr'|'result'}>} $result */
        $result = $reflectionMethod->invoke($parallelClassNodeExtractor, $processes);

        /** @var array<int, array{key: int, type: 'stderr'|'result'}> $meta */
        $meta      = $result['meta'];
        $metaTypes = [];
        foreach ($meta as $metum) {
            $metaTypes[] = $metum['type'];
        }

        $this->assertSame(['stderr', 'result', 'stderr', 'stderr'], $metaTypes);
    }

    public function testConsumeWorkerResultStoresFailureForInvalidErrorPayload(): void
    {
        $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 4);
        $resultPipe                 = fopen('php://temp', 'w+');
        $stderrPipe                 = fopen('php://temp', 'w+');
        $process                    = fopen('php://temp', 'w+');

        assert($resultPipe !== false);
        assert($stderrPipe !== false);
        assert($process !== false);

        WorkerPayloadSocket::writePayload($resultPipe, ['nodes' => [], 'error' => 123]);
        rewind($resultPipe);

        $workerProcessState = new WorkerProcessState('worker', $process, ['/tmp/Foo.php'], $stderrPipe, $resultPipe);

        $reflectionMethod = new ReflectionMethod(ParallelClassNodeExtractor::class, 'consumeWorkerResult');

        $reflectionMethod->invoke($parallelClassNodeExtractor, $workerProcessState, $resultPipe);

        $this->assertNull($workerProcessState->result);
        $this->assertInstanceOf(RuntimeException::class, $workerProcessState->resultFailure);
        $this->assertInstanceOf(RuntimeException::class, $workerProcessState->resultFailure);
        $this->assertSame(
            'Parallel analysis worker returned an invalid error payload.',
            $workerProcessState->resultFailure->getMessage()
        );
        $this->assertNull($workerProcessState->resultPipe);
    }

    public function testFinalizeWorkerThrowsWhenProcessResourceIsInvalid(): void
    {
        $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 4);
        $process                    = fopen('php://temp', 'w+');
        $stderrPipe                 = fopen('php://temp', 'w+');

        assert($process !== false);
        assert($stderrPipe !== false);

        $workerProcessState = new WorkerProcessState('worker', $process, ['/tmp/Foo.php'], $stderrPipe);
        fclose($process);

        $finalizeWorker = Closure::bind(
            fn (WorkerProcessState $workerProcessState): mixed => $this->finalizeWorker($workerProcessState),
            $parallelClassNodeExtractor,
            ParallelClassNodeExtractor::class,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to close parallel analysis worker process.');

        $finalizeWorker($workerProcessState);
    }

    private function createWorkerScript(string $contents): string
    {
        $dir    = $this->makeTemporaryDirectory('structarmed-parallel-worker');
        $script = $dir . '/worker.php';
        file_put_contents($script, $contents);

        return $script;
    }
}
