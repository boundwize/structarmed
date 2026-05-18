<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\Parallel\ValueObject\WorkerFinalizedState;
use Boundwize\StructArmed\Analyser\Parallel\ValueObject\WorkerProcessState;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use RuntimeException;

use function array_chunk;
use function array_key_exists;
use function array_merge;
use function assert;
use function ceil;
use function count;
use function dirname;
use function fclose;
use function feof;
use function fread;
use function is_array;
use function is_resource;
use function is_string;
use function max;
use function min;
use function proc_close;
use function sprintf;
use function str_replace;
use function stream_get_contents;
use function stream_set_blocking;
use function strlen;
use function substr_count;

use const PHP_BINARY;

final readonly class ParallelClassNodeExtractor
{
    /**
     * @param array<string, string|list<string>> $layers
     * @param array<string, array{pattern: string, excludePattern: string|null}> $layerPatterns
     */
    public function __construct(
        private string $basePath,
        private array $layers,
        private array $layerPatterns,
        private int $workerCount,
    ) {
    }

    /**
     * @param list<string> $files
     * @return list<ClassNode>
     */
    public function extract(array $files, ?ProgressHandlerInterface $progressHandler = null): array
    {
        if ($files === []) {
            return [];
        }

        $totalFiles  = count($files);
        $workerCount = min($this->workerCount, $totalFiles);
        $chunkSize   = $this->determineBatchSize($totalFiles, $workerCount);
        /** @var int<1, max> $chunkSize */
        $batchQueue   = array_chunk($files, $chunkSize);
        $workerCount  = min($workerCount, count($batchQueue));
        $script       = dirname(__DIR__, 3) . '/bin/structarmed.php';
        $nodes        = [];
        $failure      = null;
        $pending      = [];
        $nextWorkerId = 0;
        $nextBatch    = 0;
        $batchCount   = count($batchQueue);

        while ($nextBatch < $batchCount && count($pending) < $workerCount) {
            /** @var non-empty-list<string> $filesForWorker */
            $filesForWorker = $batchQueue[$nextBatch++];

            $pending[] = $this->startWorker(
                workerId: (string) $nextWorkerId++,
                files: $filesForWorker,
                script: $script,
                trackProgress: $progressHandler instanceof ProgressHandlerInterface,
            );
        }

        while ($pending !== []) {
            ['streams' => $readStreams, 'meta' => $streamMeta] = $this->collectReadableStreams($pending);

            if ($readStreams === []) {
                break;
            }

            $writePipes  = null;
            $exceptPipes = null;
            // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
            // to allow tests to mock stream_select in this namespace
            $selected = stream_select($readStreams, $writePipes, $exceptPipes, 0, 5000);
            // phpcs:enable

            if ($selected === false) {
                throw new RuntimeException('Unable to wait for parallel analysis worker progress.');
            }

            if ($selected === 0) {
                continue;
            }

            assert(is_array($readStreams));

            foreach ($readStreams as $readStream) {
                $meta = $streamMeta[(int) $readStream] ?? null;
                $key  = $meta['key'] ?? null;

                if ($key === null || ! isset($pending[$key])) {
                    continue;
                }

                $worker = $pending[$key];

                if (($meta['type'] ?? null) === 'result') {
                    $this->consumeWorkerResult($worker, $readStream);

                    if (! $worker->trackProgress) {
                        $stderrPipe = $worker->stderrPipe;
                        if (is_resource($stderrPipe)) {
                            $remaining = fread($stderrPipe, 8192);
                            if ($remaining !== false && $remaining !== '') {
                                $worker->stderrBuffer .= str_replace("\n", '', $remaining);
                            }

                            if (feof($stderrPipe)) {
                                fclose($stderrPipe);
                                $this->handleCompletedWorker(
                                    $pending,
                                    $key,
                                    $worker,
                                    $nodes,
                                    $failure,
                                    $batchQueue,
                                    $nextBatch,
                                    $batchCount,
                                    $nextWorkerId,
                                    $script,
                                    false,
                                );
                            }
                        }
                    }

                    continue;
                }

                $stderrPipe = $readStream;

                $data = fread($stderrPipe, 8192);
                if ($data !== false && $data !== '') {
                    $count = substr_count($data, "\n");

                    if ($count !== strlen($data)) {
                        $worker->stderrBuffer .= str_replace("\n", '', $data);
                    }

                    if ($worker->trackProgress) {
                        $fileCount = count($worker->files);
                        for ($i = 0; $i < $count; $i++) {
                            $fileIdx = $worker->filesAdvanced;
                            if ($fileIdx < $fileCount) {
                                $progressHandler?->advance($worker->files[$fileIdx]);
                                $worker->filesAdvanced++;
                            }
                        }
                    }
                }

                if (! feof($stderrPipe)) {
                    continue;
                }

                fclose($stderrPipe);

                $this->handleCompletedWorker(
                    $pending,
                    $key,
                    $worker,
                    $nodes,
                    $failure,
                    $batchQueue,
                    $nextBatch,
                    $batchCount,
                    $nextWorkerId,
                    $script,
                    true,
                );
            }
        }

        if ($failure !== null) {
            throw new RuntimeException($failure);
        }

        return $nodes;
    }

    /**
     * @param array<int, WorkerProcessState> $processes
     * @return array{streams: list<resource>, meta: array<int, array{key: int, type: 'stderr'|'result'}>}
     */
    private function collectReadableStreams(array $processes): array
    {
        $readStreams = [];
        $streamMeta  = [];

        foreach ($processes as $key => $worker) {
            $stderrPipe = $worker->stderrPipe;

            if (is_resource($stderrPipe)) {
                $readStreams[]                 = $stderrPipe;
                $streamMeta[(int) $stderrPipe] = ['key' => $key, 'type' => 'stderr'];
            }

            $resultPipe = $worker->resultPipe;

            if (
                is_resource($resultPipe)
                && $worker->result === null
                && $worker->resultFailure === null
            ) {
                $readStreams[]                 = $resultPipe;
                $streamMeta[(int) $resultPipe] = ['key' => $key, 'type' => 'result'];
            }
        }

        return ['streams' => $readStreams, 'meta' => $streamMeta];
    }

    /**
     * @param resource $stream
     */
    private function consumeWorkerResult(WorkerProcessState $workerProcessState, mixed $stream): void
    {
        try {
            $result = WorkerPayloadSocket::readPayload($stream);

            if (
                ! isset($result['nodes'])
                || ! is_array($result['nodes'])
                || ! array_key_exists('error', $result)
            ) {
                throw new RuntimeException('Parallel analysis worker returned an invalid payload.');
            }

            $error = $result['error'];

            if (! is_string($error) && $error !== null) {
                throw new RuntimeException('Parallel analysis worker returned an invalid error payload.');
            }

            /** @var list<ClassNode> $nodes */
            $nodes = $result['nodes'];

            $workerProcessState->result = [
                'nodes' => $nodes,
                'error' => $error,
            ];
        } catch (RuntimeException $runtimeException) {
            $workerProcessState->resultFailure = $runtimeException;
        } finally {
            fclose($stream);
            $workerProcessState->resultPipe = null;
        }
    }

    /**
     * @param list<string> $files
     */
    private function startWorker(
        string $workerId,
        array $files,
        string $script,
        bool $trackProgress
    ): WorkerProcessState {
        // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
        // to avoid error in test that mock it
        $process = proc_open(
        // phpcs:enable
            [PHP_BINARY, $script, '--internal-worker'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if ($process === false) {
            throw new RuntimeException('Unable to start parallel analysis worker.');
        }

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start parallel analysis worker.');
        }

        assert(isset($pipes[0]) && isset($pipes[1]) && isset($pipes[2]));
        $payloadPipe = $pipes[0];
        WorkerPayloadSocket::writePayload($payloadPipe, [
            'basePath'      => $this->basePath,
            'layers'        => $this->layers,
            'layerPatterns' => $this->layerPatterns,
            'files'         => $files,
            'trackProgress' => $trackProgress,
        ]);
        fclose($payloadPipe);

        $resultPipe = $pipes[1];
        $stderrPipe = $pipes[2];
        stream_set_blocking($stderrPipe, false);

        return new WorkerProcessState(
            workerId: $workerId,
            process: $process,
            files: $files,
            stderrPipe: $stderrPipe,
            resultPipe: $resultPipe,
            trackProgress: $trackProgress,
        );
    }

    private function determineBatchSize(int $totalFiles, int $workerCount): int
    {
        if ($workerCount <= 1) {
            return max(1, $totalFiles);
        }

        $filesPerWorker         = (int) ceil($totalFiles / $workerCount);
        $targetBatchesPerWorker = min(4, max(1, (int) ceil($filesPerWorker / 256)));
        $targetBatchCount       = min($totalFiles, $workerCount * $targetBatchesPerWorker);

        return max(1, (int) ceil($totalFiles / $targetBatchCount));
    }

    /**
     * @param array<int, WorkerProcessState> $pending
     * @param list<ClassNode> $nodes
     * @param list<list<string>> $batchQueue
     */
    private function handleCompletedWorker(
        array &$pending,
        int $key,
        WorkerProcessState $workerProcessState,
        array &$nodes,
        ?string &$failure,
        array $batchQueue,
        int &$nextBatch,
        int $batchCount,
        int &$nextWorkerId,
        string $script,
        bool $trackProgress,
    ): void {
        $workerFinalizedState = $this->finalizeWorker($workerProcessState);
        $result               = $workerFinalizedState->result;
        $resultFailure        = $workerFinalizedState->resultFailure;
        $stderr               = $workerFinalizedState->stderr;
        $exitCode             = $workerFinalizedState->exitCode;

        if ($resultFailure instanceof RuntimeException) {
            if ($exitCode !== 0 && $stderr !== '') {
                $failure ??= sprintf(
                    "Parallel analysis worker failed: unknown error\n%s",
                    $stderr
                );
            } else {
                $failure ??= $resultFailure->getMessage();
            }

            unset($pending[$key]);

            return;
        }

        if ($result === null) {
            $failure ??= 'Parallel analysis worker returned an invalid payload.';

            unset($pending[$key]);

            return;
        }

        $error = $result['error'];

        if ($error !== null || $exitCode !== 0) {
            $failure ??= sprintf(
                "Parallel analysis worker failed: %s%s",
                $error ?? 'unknown error',
                $stderr !== '' ? "\n" . $stderr : ''
            );

            unset($pending[$key]);

            return;
        }

        /** @var list<ClassNode> $workerNodes */
        $workerNodes = $result['nodes'];
        $nodes       = array_merge($nodes, $workerNodes);

        unset($pending[$key]);

        if ($nextBatch < $batchCount && $failure === null) {
            /** @var non-empty-list<string> $filesForWorker */
            $filesForWorker = $batchQueue[$nextBatch++];

            $pending[] = $this->startWorker(
                workerId: (string) $nextWorkerId++,
                files: $filesForWorker,
                script: $script,
                trackProgress: $trackProgress,
            );
        }
    }

    private function finalizeWorker(WorkerProcessState $workerProcessState): WorkerFinalizedState
    {
        if (is_resource($workerProcessState->resultPipe)) {
            $this->consumeWorkerResult($workerProcessState, $workerProcessState->resultPipe);
        }

        $stderrPipe = $workerProcessState->stderrPipe;
        $stderr     = $workerProcessState->stderrBuffer;

        if (is_resource($stderrPipe)) {
            $stderr .= (string) stream_get_contents($stderrPipe);
        }

        if (is_resource($stderrPipe)) {
            fclose($stderrPipe);
        }

        $procResource = $workerProcessState->process;

        if (! is_resource($procResource)) {
            throw new RuntimeException('Unable to close parallel analysis worker process.');
        }

        $exitCode = proc_close($procResource);

        return new WorkerFinalizedState(
            result: $workerProcessState->result,
            resultFailure: $workerProcessState->resultFailure,
            stderr: $stderr,
            exitCode: $exitCode,
        );
    }
}
