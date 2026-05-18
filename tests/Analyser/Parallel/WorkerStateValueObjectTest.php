<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\Parallel\ValueObject\WorkerFinalizedState;
use Boundwize\StructArmed\Analyser\Parallel\ValueObject\WorkerProcessState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fclose;
use function fopen;

#[CoversClass(WorkerProcessState::class)]
#[CoversClass(WorkerFinalizedState::class)]
final class WorkerStateValueObjectTest extends TestCase
{
    public function testWorkerProcessStateStoresMutableWorkerState(): void
    {
        $process    = fopen('php://temp', 'w+');
        $stderrPipe = fopen('php://temp', 'w+');
        $resultPipe = fopen('php://temp', 'w+');

        $this->assertNotFalse($process);
        $this->assertNotFalse($stderrPipe);
        $this->assertNotFalse($resultPipe);

        try {
            $workerProcessState = new WorkerProcessState(
                'worker-1',
                $process,
                ['/tmp/Foo.php'],
                $stderrPipe,
                $resultPipe
            );

            $this->assertSame('worker-1', $workerProcessState->workerId);
            $this->assertSame(['/tmp/Foo.php'], $workerProcessState->files);
            $this->assertSame(0, $workerProcessState->filesAdvanced);
            $this->assertSame('', $workerProcessState->stderrBuffer);
            $this->assertNull($workerProcessState->result);
            $this->assertNotInstanceOf(RuntimeException::class, $workerProcessState->resultFailure);

            $workerProcessState->filesAdvanced = 1;
            $workerProcessState->result        = ['nodes' => [], 'error' => null];
            $workerProcessState->resultFailure = new RuntimeException('failed');

            $this->assertSame(1, $workerProcessState->filesAdvanced);
            $this->assertSame(['nodes' => [], 'error' => null], $workerProcessState->result);
            $this->assertInstanceOf(RuntimeException::class, $workerProcessState->resultFailure);
            $this->assertSame('failed', $workerProcessState->resultFailure->getMessage());
        } finally {
            fclose($process);
            fclose($stderrPipe);
            fclose($resultPipe);
        }
    }

    public function testWorkerFinalizedStateStoresFinalWorkerData(): void
    {
        $runtimeException     = new RuntimeException('boom');
        $workerFinalizedState = new WorkerFinalizedState(
            ['nodes' => [], 'error' => null],
            $runtimeException,
            'stderr',
            1
        );

        $this->assertSame(['nodes' => [], 'error' => null], $workerFinalizedState->result);
        $this->assertSame($runtimeException, $workerFinalizedState->resultFailure);
        $this->assertSame('stderr', $workerFinalizedState->stderr);
        $this->assertSame(1, $workerFinalizedState->exitCode);
    }
}
