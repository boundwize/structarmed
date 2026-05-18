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
use function assert;
use function ceil;
use function count;
use function dirname;
use function fclose;
use function feof;
use function fread;
use function is_array;
use function is_dir;
use function is_resource;
use function is_string;
use function max;
use function min;
use function mkdir;
use function proc_close;
use function sprintf;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function substr_count;
use function usleep;

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
        private ?string $cacheDirectory = null,
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

        $this->ensureCacheDirectoryExists();

        $totalFiles  = count($files);
        $workerCount = min($this->workerCount, $totalFiles);
        $chunkSize   = (int) ceil($totalFiles / $workerCount);
        $script      = dirname(__DIR__, 3) . '/bin/structarmed.php';
        $processes   = [];

        foreach (array_chunk($files, max(1, $chunkSize)) as $chunk) {
            ['server' => $serverSocket, 'address' => $socketAddress] = $this->createWorkerListener();

            // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
            // to avoid error in test that mock it
            $process = proc_open(
            // phpcs:enable
                [PHP_BINARY, $script, '--internal-worker', $socketAddress],
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

            $workerSocket = stream_socket_accept($serverSocket, 5);
            fclose($serverSocket);

            if ($workerSocket === false) {
                $stderrPipe = $pipes[2];
                $stderr     = is_resource($stderrPipe) ? (string) stream_get_contents($stderrPipe) : '';

                fclose($pipes[1]);
                fclose($stderrPipe);

                $procResource = $process;
                assert(is_resource($procResource));
                proc_close($procResource);

                throw new RuntimeException(sprintf(
                    'Parallel analysis worker failed: unable to establish worker socket.%s',
                    $stderr !== '' ? "\n" . $stderr : ''
                ));
            }

            WorkerPayloadSocket::writePayload($workerSocket, [
                'basePath'      => $this->basePath,
                'layers'        => $this->layers,
                'layerPatterns' => $this->layerPatterns,
                'files'         => $chunk,
            ]);

            $stdoutPipe = $pipes[1];
            $stderrPipe = $pipes[2];
            stream_set_blocking($stdoutPipe, false);

            $processes[] = [
                'process'       => $process,
                'files'         => $chunk,
                'filesAdvanced' => 0,
                'socket'        => $workerSocket,
                'stdoutPipe'    => $stdoutPipe,
                'stderrPipe'    => $stderrPipe,
            ];
        }

        $nodes   = [];
        $failure = null;
        $pending = $processes;

        while ($pending !== []) {
            $anyActivity = false;

            foreach (array_keys($pending) as $key) {
                $stdoutPipe = $pending[$key]['stdoutPipe'];

                $data = fread($stdoutPipe, 8192);
                if ($data !== false && $data !== '') {
                    $count = substr_count($data, "\n");
                    for ($i = 0; $i < $count; $i++) {
                        $fileIdx = $pending[$key]['filesAdvanced'];
                        if ($fileIdx < count($pending[$key]['files'])) {
                            $progressHandler?->advance($pending[$key]['files'][$fileIdx]);
                            $pending[$key]['filesAdvanced']++;
                        }
                    }

                    $anyActivity = true;
                }

                if (! feof($stdoutPipe)) {
                    continue;
                }

                // Pipe EOF means the worker exited and the OS closed its write end.
                // proc_close() has not been called yet so waitpid() inside it correctly
                // returns the real exit code (no double-waitpid race with proc_get_status).
                fclose($stdoutPipe);

                $stderrPipe = $pending[$key]['stderrPipe'];
                $stderr     = (string) stream_get_contents($stderrPipe);
                fclose($stderrPipe);

                $procResource = $pending[$key]['process'];
                assert(is_resource($procResource));
                $exitCode = proc_close($procResource);

                try {
                    $result = WorkerPayloadSocket::readPayload($pending[$key]['socket']);

                    if (
                        ! is_array($result)
                        || ! isset($result['nodes'])
                        || ! is_array($result['nodes'])
                        || ! array_key_exists('error', $result)
                    ) {
                        throw new RuntimeException('Parallel analysis worker returned an invalid payload.');
                    }

                    $error = $result['error'];

                    if ($error !== null && ! is_string($error)) {
                        throw new RuntimeException('Parallel analysis worker returned an invalid error payload.');
                    }

                    if ($error !== null || $exitCode !== 0) {
                        throw new RuntimeException(sprintf(
                            "Parallel analysis worker failed: %s%s",
                            $error ?? 'unknown error',
                            $stderr !== '' ? "\n" . $stderr : ''
                        ));
                    }

                    /** @var list<ClassNode> $workerNodes */
                    $workerNodes = $result['nodes'];
                    $nodes       = array_merge($nodes, $workerNodes);
                } catch (RuntimeException $runtimeException) {
                    if ($exitCode !== 0 && $stderr !== '') {
                        $failure ??= sprintf(
                            "Parallel analysis worker failed: unknown error\n%s",
                            $stderr
                        );
                    } else {
                        $failure ??= $runtimeException->getMessage();
                    }
                } finally {
                    fclose($pending[$key]['socket']);
                }

                unset($pending[$key]);
                $anyActivity = true;
            }

            if (! $anyActivity) {
                usleep(5000);
            }
        }

        if ($failure !== null) {
            throw new RuntimeException($failure);
        }

        return $nodes;
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

    private function ensureCacheDirectoryExists(): void
    {
        if ($this->cacheDirectory !== null && ! is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0777, true);
        }
    }
}
