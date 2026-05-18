<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\Parallel\ParallelClassNodeExtractor;
use Boundwize\StructArmed\Analyser\Parallel\ValueObject\WorkerFinalizedState;
use Boundwize\StructArmed\Analyser\Parallel\ValueObject\WorkerProcessState;
use Boundwize\StructArmed\Analyser\Parallel\WorkerPayloadSocket;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function assert;
use function count;
use function fclose;
use function file_put_contents;
use function fopen;
use function is_resource;
use function proc_open;
use function rewind;

use const PHP_BINARY;

#[CoversClass(ParallelClassNodeExtractor::class)]
final class ParallelClassNodeExtractorTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    protected function setUp(): void
    {
        $GLOBALS['mock_proc_open']              = false;
        $GLOBALS['mock_proc_open_callback']     = null;
        $GLOBALS['mock_proc_open_command']      = null;
        $GLOBALS['mock_stream_select_callback'] = null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['mock_proc_open']              = false;
        $GLOBALS['mock_proc_open_callback']     = null;
        $GLOBALS['mock_proc_open_command']      = null;
        $GLOBALS['mock_stream_select_callback'] = null;
    }

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

    public function testExtractThrowsUnknownErrorWhenWorkerFailsWithStderr(): void
    {
        $script = $this->createWorkerScript(<<<'PHP'
<?php
while (! feof(STDIN)) {
    fread(STDIN, 8192);
}

fwrite(STDERR, 'worker stderr');
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

    public function testExtractThrowsWhenWorkerReturnsNoResultPayload(): void
    {
        $GLOBALS['mock_proc_open_callback'] = static function (
            array|string $command,
            array $descriptorspec,
            array|null &$pipes
        ): mixed {
            $process = proc_open(
                [PHP_BINARY, '-r', 'while (! feof(STDIN)) { fread(STDIN, 8192); }'],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $realPipes,
            );

            assert(is_resource($process));
            assert(isset($realPipes[0], $realPipes[1], $realPipes[2]));

            fclose($realPipes[1]);
            $pipes = [
                0 => $realPipes[0],
                1 => 'not-a-stream',
                2 => $realPipes[2],
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

    public function testExtractStartsQueuedBatchesAfterWorkerSucceeds(): void
    {
        $script = $this->createSuccessfulWorkerScript();
        $files  = [];

        for ($i = 0; $i < 513; $i++) {
            $files[] = '/tmp/File' . $i . '.php';
        }

        $GLOBALS['mock_proc_open_command'] = [PHP_BINARY, $script];

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 2);

            $this->assertSame([], $parallelClassNodeExtractor->extract($files));
        } finally {
            $GLOBALS['mock_proc_open_command'] = null;
        }
    }

    public function testExtractThrowsWhenStreamSelectFails(): void
    {
        $script = $this->createSuccessfulWorkerScript();

        $GLOBALS['mock_proc_open_command']      = [PHP_BINARY, $script];
        $GLOBALS['mock_stream_select_callback'] = static fn (
            ?array &$read,
            ?array &$write,
            ?array &$except,
            ?int $seconds,
            ?int $microseconds = null
        ): false => false;

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to wait for parallel analysis worker progress.');

            $parallelClassNodeExtractor->extract(['/tmp/Foo.php']);
        } finally {
            $GLOBALS['mock_proc_open_command']      = null;
            $GLOBALS['mock_stream_select_callback'] = null;
        }
    }

    public function testExtractSkipsReadableStreamWithoutMetadata(): void
    {
        $script        = $this->createSuccessfulWorkerScript();
        $unknownStream = fopen('php://temp', 'w+');

        assert($unknownStream !== false);

        $GLOBALS['mock_proc_open_command']      = [PHP_BINARY, $script];
        $GLOBALS['mock_stream_select_callback'] = static function (
            ?array &$read,
            ?array &$write,
            ?array &$except,
            ?int $seconds,
            ?int $microseconds = null
        ) use ($unknownStream): int|false {
            /** @var int $callCount */
            static $callCount = 0;

            $callCount++;

            if ($callCount === 1) {
                $read = [$unknownStream];

                return 1;
            }

            $GLOBALS['mock_stream_select_callback'] = null;

            if ($read === null) {
                return false;
            }

            return count($read);
        };

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 1);

            $this->assertSame([], $parallelClassNodeExtractor->extract(['/tmp/Foo.php']));
        } finally {
            $GLOBALS['mock_proc_open_command']      = null;
            $GLOBALS['mock_stream_select_callback'] = null;
            fclose($unknownStream);
        }
    }

    public function testExtractThrowsWhenProcOpenFails(): void
    {
        $GLOBALS['mock_proc_open'] = true;

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to start parallel analysis worker.');

            $parallelClassNodeExtractor->extract(['/tmp/Foo.php']);
        } finally {
            $GLOBALS['mock_proc_open'] = false;
        }
    }

    public function testExtractThrowsWhenProcOpenReturnsNonResource(): void
    {
        $GLOBALS['mock_proc_open_callback'] = static function (
            array|string $command,
            array $descriptorspec,
            array|null &$pipes
        ): string {
            $pipes = [];

            return 'not-a-process';
        };

        try {
            $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to start parallel analysis worker.');

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

    public function testConsumeWorkerResultStoresFailureForInvalidPayloadShape(): void
    {
        $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 4);
        $resultPipe                 = fopen('php://temp', 'w+');
        $stderrPipe                 = fopen('php://temp', 'w+');
        $process                    = fopen('php://temp', 'w+');

        assert($resultPipe !== false);
        assert($stderrPipe !== false);
        assert($process !== false);

        WorkerPayloadSocket::writePayload($resultPipe, ['error' => null]);
        rewind($resultPipe);

        $workerProcessState = new WorkerProcessState('worker', $process, ['/tmp/Foo.php'], $stderrPipe, $resultPipe);

        $reflectionMethod = new ReflectionMethod(ParallelClassNodeExtractor::class, 'consumeWorkerResult');

        $reflectionMethod->invoke($parallelClassNodeExtractor, $workerProcessState, $resultPipe);

        $this->assertNull($workerProcessState->result);
        $this->assertInstanceOf(RuntimeException::class, $workerProcessState->resultFailure);
        $this->assertSame(
            'Parallel analysis worker returned an invalid payload.',
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

    public function testFinalizeWorkerConsumesOpenResultPipe(): void
    {
        $parallelClassNodeExtractor = new ParallelClassNodeExtractor('/tmp', [], [], 4);
        $process                    = proc_open(
            [PHP_BINARY, '-r', ''],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        assert(is_resource($process));
        assert(isset($pipes[0], $pipes[1], $pipes[2]));

        fclose($pipes[0]);
        fclose($pipes[1]);

        $resultPipe = fopen('php://temp', 'w+');
        assert($resultPipe !== false);

        WorkerPayloadSocket::writePayload($resultPipe, ['nodes' => [], 'error' => null]);
        rewind($resultPipe);

        $workerProcessState = new WorkerProcessState('worker', $process, ['/tmp/Foo.php'], $pipes[2], $resultPipe);

        $finalizeWorker = Closure::bind(
            fn (WorkerProcessState $workerProcessState): mixed => $this->finalizeWorker($workerProcessState),
            $parallelClassNodeExtractor,
            ParallelClassNodeExtractor::class,
        );

        $finalizedWorker = $finalizeWorker($workerProcessState);
        $this->assertInstanceOf(
            WorkerFinalizedState::class,
            $finalizedWorker
        );

        $this->assertSame(['nodes' => [], 'error' => null], $finalizedWorker->result);
        $this->assertNotInstanceOf(RuntimeException::class, $finalizedWorker->resultFailure);
        $this->assertSame('', $finalizedWorker->stderr);
        $this->assertSame(0, $finalizedWorker->exitCode);
    }

    private function createSuccessfulWorkerScript(): string
    {
        return $this->createWorkerScript(<<<'PHP'
<?php
$header = fread(STDIN, 4);

if ($header === false || $header === '') {
    exit(1);
}

$decodedHeader = unpack('Nlength', $header);
$length        = $decodedHeader['length'];
$payload       = '';

while (strlen($payload) < $length) {
    $chunk = fread(STDIN, $length - strlen($payload));

    if ($chunk === false || $chunk === '') {
        exit(1);
    }

    $payload .= $chunk;
}

$result = serialize(['nodes' => [], 'error' => null]);

fwrite(STDOUT, pack('N', strlen($result)) . $result);
PHP);
    }

    private function createWorkerScript(string $contents): string
    {
        $dir    = $this->makeTemporaryDirectory('structarmed-parallel-worker');
        $script = $dir . '/worker.php';
        file_put_contents($script, $contents);

        return $script;
    }
}
