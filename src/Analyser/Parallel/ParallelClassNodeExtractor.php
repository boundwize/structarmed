<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use RuntimeException;

use function array_chunk;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_values;
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
use function stream_get_contents;
use function stream_set_blocking;
use function stream_select;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
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
        $chunkSize   = (int) ceil($totalFiles / $workerCount);
        $script      = dirname(__DIR__, 3) . '/bin/structarmed.php';
        ['server' => $serverSocket, 'address' => $socketAddress] = $this->createWorkerListener();
        $processes   = [];

        foreach (array_chunk($files, max(1, $chunkSize)) as $workerIndex => $chunk) {
            $workerId = (string) $workerIndex;

            // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
            // to avoid error in test that mock it
            $process = proc_open(
            // phpcs:enable
                [PHP_BINARY, $script, '--internal-worker', $socketAddress, $workerId],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );

            if ($process === false) {
                fclose($serverSocket);

                throw new RuntimeException('Unable to start parallel analysis worker.');
            }

            assert(isset($pipes[0]) && isset($pipes[1]) && isset($pipes[2]));
            fclose($pipes[0]);

            $stdoutPipe = $pipes[1];
            $stderrPipe = $pipes[2];
            stream_set_blocking($stdoutPipe, false);

            $processes[] = new WorkerProcessState(
                workerId: $workerId,
                process: $process,
                files: $chunk,
                stdoutPipe: $stdoutPipe,
                stderrPipe: $stderrPipe,
            );
        }

        try {
            $processes = $this->acceptWorkerConnections($serverSocket, $processes);
        } finally {
            fclose($serverSocket);
        }

        $nodes   = [];
        $failure = null;
        $pending = $processes;

        while ($pending !== []) {
            ['streams' => $readStreams, 'meta' => $streamMeta] = $this->collectReadableStreams($pending);

            if ($readStreams === []) {
                break;
            }

            $writePipes = null;
            $exceptPipes = null;
            $selected = stream_select($readStreams, $writePipes, $exceptPipes, 0, 50000);

            if ($selected === false) {
                throw new RuntimeException('Unable to wait for parallel analysis worker progress.');
            }

            if ($selected === 0) {
                continue;
            }

            foreach ($readStreams as $stream) {
                $meta = $streamMeta[(int) $stream] ?? null;
                $key = $meta['key'] ?? null;

                if ($key === null || ! isset($pending[$key])) {
                    continue;
                }

                $worker = $pending[$key];

                if (($meta['type'] ?? null) === 'socket') {
                    $this->consumeWorkerSocket($worker, $stream);

                    continue;
                }

                $stdoutPipe = $stream;

                $data = fread($stdoutPipe, 8192);
                if ($data !== false && $data !== '') {
                    $count = substr_count($data, "\n");
                    for ($i = 0; $i < $count; $i++) {
                        $fileIdx = $worker->filesAdvanced;
                        if ($fileIdx < count($worker->files)) {
                            $progressHandler?->advance($worker->files[$fileIdx]);
                            $worker->filesAdvanced++;
                        }
                    }
                }

                if (! feof($stdoutPipe)) {
                    continue;
                }

                // Pipe EOF means the worker exited and the OS closed its write end.
                // proc_close() has not been called yet so waitpid() inside it correctly
                // returns the real exit code (no double-waitpid race with proc_get_status).
                fclose($stdoutPipe);

                $finalizedWorker = $this->finalizeWorker($worker);
                $result = $finalizedWorker->result;
                $socketFailure = $finalizedWorker->socketFailure;
                $stderr = $finalizedWorker->stderr;
                $exitCode = $finalizedWorker->exitCode;

                if ($socketFailure !== null) {
                    if ($exitCode !== 0 && $stderr !== '') {
                        $failure ??= sprintf(
                            "Parallel analysis worker failed: unknown error\n%s",
                            $stderr
                        );
                    } else {
                        $failure ??= $socketFailure->getMessage();
                    }

                    unset($pending[$key]);

                    continue;
                }

                if (
                    ! is_array($result)
                    || ! isset($result['nodes'])
                    || ! is_array($result['nodes'])
                    || ! array_key_exists('error', $result)
                ) {
                    $failure ??= 'Parallel analysis worker returned an invalid payload.';

                    unset($pending[$key]);

                    continue;
                }

                $error = $result['error'];

                if ($error !== null && ! is_string($error)) {
                    $failure ??= 'Parallel analysis worker returned an invalid error payload.';

                    unset($pending[$key]);

                    continue;
                }

                if ($error !== null || $exitCode !== 0) {
                    $failure ??= sprintf(
                        "Parallel analysis worker failed: %s%s",
                        $error ?? 'unknown error',
                        $stderr !== '' ? "\n" . $stderr : ''
                    );

                    unset($pending[$key]);

                    continue;
                }

                /** @var list<ClassNode> $workerNodes */
                $workerNodes = $result['nodes'];
                $nodes       = array_merge($nodes, $workerNodes);

                unset($pending[$key]);
            }
        }

        if ($failure !== null) {
            throw new RuntimeException($failure);
        }

        return $nodes;
    }

    /**
    * @param list<WorkerProcessState> $processes
     * @return array{streams: list<resource>, meta: array<int, array{key: int, type: 'stdout'|'socket'}>}
     */
    private function collectReadableStreams(array $processes): array
    {
        $readStreams = [];
        $streamMeta  = [];

        foreach (array_keys($processes) as $key) {
            $worker = $processes[$key];
            $stdoutPipe = $worker->stdoutPipe;

            if (is_resource($stdoutPipe)) {
                $readStreams[] = $stdoutPipe;
                $streamMeta[(int) $stdoutPipe] = ['key' => $key, 'type' => 'stdout'];
            }

            $socket = $worker->socket;

            if (
                is_resource($socket)
                && $worker->result === null
                && $worker->socketFailure === null
            ) {
                $readStreams[] = $socket;
                $streamMeta[(int) $socket] = ['key' => $key, 'type' => 'socket'];
            }
        }

        return ['streams' => $readStreams, 'meta' => $streamMeta];
    }

    /**
     * @param resource $stream
     * @param resource $stream
     */
    private function consumeWorkerSocket(WorkerProcessState $worker, mixed $stream): void
    {
        try {
            $worker->result = WorkerPayloadSocket::readPayload($stream);
        } catch (RuntimeException $runtimeException) {
            $worker->socketFailure = $runtimeException;
        } finally {
            fclose($stream);
            $worker->socket = null;
        }
    }

    /**
     * @return WorkerFinalizedState
     */
    private function finalizeWorker(WorkerProcessState $worker): WorkerFinalizedState
    {
        if (is_resource($worker->socket)) {
            $this->consumeWorkerSocket($worker, $worker->socket);
        }

        $stderrPipe = $worker->stderrPipe;
        $stderr     = is_resource($stderrPipe) ? (string) stream_get_contents($stderrPipe) : '';

        if (is_resource($stderrPipe)) {
            fclose($stderrPipe);
        }

        $procResource = $worker->process;
        assert(is_resource($procResource));
        $exitCode = proc_close($procResource);

        return new WorkerFinalizedState(
            result: $worker->result,
            socketFailure: $worker->socketFailure,
            stderr: $stderr,
            exitCode: $exitCode,
        );
    }

    /**
        * @param list<WorkerProcessState> $processes
        * @return list<WorkerProcessState>
     */
    private function acceptWorkerConnections(mixed $serverSocket, array $processes): array
    {
        $byWorkerId = [];

        foreach ($processes as $key => $process) {
            $byWorkerId[$process->workerId] = $key;
        }

        $acceptedWorkers = 0;

        while ($acceptedWorkers < count($processes)) {
            $workerSocket = stream_socket_accept($serverSocket, 5);

            if ($workerSocket === false) {
                throw new RuntimeException('Parallel analysis worker failed: unable to establish worker socket.');
            }

            $hello = WorkerPayloadSocket::readPayload($workerSocket);

            if (! isset($hello['workerId']) || ! is_string($hello['workerId']) || ! isset($byWorkerId[$hello['workerId']])) {
                fclose($workerSocket);

                throw new RuntimeException('Parallel analysis worker returned an invalid hello payload.');
            }

            $key = $byWorkerId[$hello['workerId']];

            if ($processes[$key]->socket !== null) {
                fclose($workerSocket);

                throw new RuntimeException('Parallel analysis worker connected more than once.');
            }

            WorkerPayloadSocket::writePayload($workerSocket, [
                'basePath'      => $this->basePath,
                'layers'        => $this->layers,
                'layerPatterns' => $this->layerPatterns,
                'files'         => $processes[$key]->files,
            ]);

            $processes[$key]->socket = $workerSocket;
            $acceptedWorkers++;
        }

        return array_values($processes);
    }

    /**
     * @return array{server: resource, address: string}
     */
    private function createWorkerListener(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');

        if ($server === false) {
            throw new RuntimeException('Unable to create socket for parallel analysis worker.');
        }

        $address = stream_socket_get_name($server, false);

        if (! is_string($address) || $address === '') {
            fclose($server);

            throw new RuntimeException('Unable to resolve socket address for parallel analysis worker.');
        }

        return [
            'server' => $server,
            'address' => 'tcp://' . $address,
        ];
    }
}
