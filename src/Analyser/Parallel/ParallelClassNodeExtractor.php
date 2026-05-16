<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use RuntimeException;

use function array_column;
use function array_fill;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_values;
use function assert;
use function count;
use function dirname;
use function fclose;
use function feof;
use function file_put_contents;
use function filesize;
use function fread;
use function is_array;
use function is_dir;
use function is_resource;
use function is_string;
use function min;
use function mkdir;
use function proc_close;
use function serialize;
use function sprintf;
use function stream_set_blocking;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;
use function unserialize;
use function usleep;
use function usort;

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

        $workerCount = min($this->workerCount, count($files));
        $script      = dirname(__DIR__, 3) . '/bin/structarmed.php';

        $queue      = $this->buildQueue($files, $workerCount);
        $queueCount = count($queue);
        $queueIdx   = 0;
        $active     = [];
        $nodes      = [];
        $failure    = null;

        while (count($active) < $workerCount && $queueIdx < $queueCount) {
            $active[] = $this->spawnWorker($queue[$queueIdx++], $script);
        }

        while ($active !== []) {
            $anyActivity = false;

            foreach (array_keys($active) as $key) {
                $stdoutPipe = $active[$key]['stdoutPipe'];

                $data = fread($stdoutPipe, 8192);
                if ($data !== false && $data !== '') {
                    $count = substr_count($data, "\n");
                    for ($i = 0; $i < $count; $i++) {
                        $fileIdx = $active[$key]['filesAdvanced'];
                        if ($fileIdx < count($active[$key]['files'])) {
                            $progressHandler?->advance($active[$key]['files'][$fileIdx]);
                            $active[$key]['filesAdvanced']++;
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

                $exitCode = proc_close($active[$key]['process']);

                try {
                    // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
                    $result = unserialize((string) file_get_contents($active[$key]['outputFile']));
                    // phpcs:enable

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
                        // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
                        // to avoid error in test that mock it
                        $stderr = (string) file_get_contents($active[$key]['stderrFile']);
                        // phpcs:enable

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
                    $failure ??= $runtimeException->getMessage();
                } finally {
                    $this->cleanup([
                        $active[$key]['inputFile'],
                        $active[$key]['outputFile'],
                        $active[$key]['stderrFile'],
                    ]);
                }

                unset($active[$key]);
                $anyActivity = true;

                if ($queueIdx < $queueCount) {
                    $active[] = $this->spawnWorker($queue[$queueIdx++], $script);
                }
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
     * Distributes files across exactly $workerCount batches using the Longest
     * Processing Time (LPT) algorithm: sort by size descending, then assign each
     * file to the least-loaded bucket. This produces one balanced wave — each
     * worker gets roughly equal total byte weight — so no sequential second wave
     * is needed and wall time matches a single parallel sweep.
     *
     * @param list<string> $files
     * @return list<list<string>>
     */
    private function buildQueue(array $files, int $workerCount): array
    {
        $sized = [];
        foreach ($files as $file) {
            $sized[] = ['file' => $file, 'size' => @filesize($file) ?: 1];
        }

        usort($sized, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        /** @var array<int, array{files: list<string>, load: int}> $buckets */
        $buckets = array_fill(0, $workerCount, ['files' => [], 'load' => 0]);

        foreach ($sized as ['file' => $file, 'size' => $size]) {
            $minIdx = 0;
            for ($i = 1; $i < $workerCount; $i++) {
                if ($buckets[$i]['load'] < $buckets[$minIdx]['load']) {
                    $minIdx = $i;
                }
            }

            $buckets[$minIdx] = [
                'files' => [...$buckets[$minIdx]['files'], $file],
                'load'  => $buckets[$minIdx]['load'] + $size,
            ];
        }

        return array_values(array_filter(
            array_column($buckets, 'files'),
            static fn (array $chunk): bool => $chunk !== [],
        ));
    }

    /**
     * @param list<string> $chunk
     * @return array{process: resource, files: list<string>, filesAdvanced: int, inputFile: string, outputFile: string, stderrFile: string, stdoutPipe: resource}
     */
    private function spawnWorker(array $chunk, string $script): array
    {
        [
            'inputFile'  => $inputFile,
            'outputFile' => $outputFile,
            'stderrFile' => $stderrFile,
        ] = $this->createWorkerFiles();

        file_put_contents($inputFile, serialize([
            'basePath'      => $this->basePath,
            'layers'        => $this->layers,
            'layerPatterns' => $this->layerPatterns,
            'files'         => $chunk,
        ]));

        // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
        // to avoid error in test that mock it
        $process = proc_open(
        // phpcs:enable
            [PHP_BINARY, $script, '--internal-worker', $inputFile, $outputFile],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $stderrFile, 'w'],
            ],
            $pipes,
        );

        if ($process === false) {
            $this->cleanup([$inputFile, $outputFile, $stderrFile]);

            throw new RuntimeException('Unable to start parallel analysis worker.');
        }

        assert(isset($pipes[0]) && isset($pipes[1]));
        fclose($pipes[0]);

        $stdoutPipe = $pipes[1];
        assert(is_resource($process));
        assert(is_resource($stdoutPipe));
        stream_set_blocking($stdoutPipe, false);

        return [
            'process'       => $process,
            'files'         => $chunk,
            'filesAdvanced' => 0,
            'inputFile'     => $inputFile,
            'outputFile'    => $outputFile,
            'stderrFile'    => $stderrFile,
            'stdoutPipe'    => $stdoutPipe,
        ];
    }

    /**
     * @return array{inputFile: string, outputFile: string, stderrFile: string}
     */
    private function createWorkerFiles(): array
    {
        return [
            'inputFile'  => $this->temporaryFile(),
            'outputFile' => $this->temporaryFile(),
            'stderrFile' => $this->temporaryFile(),
        ];
    }

    private function temporaryFile(): string
    {
        $dir = $this->cacheDirectory ?? sys_get_temp_dir();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
        // to avoid error in test that mock it
        $file = tempnam($dir, 'structarmed-worker-');
        // phpcs:enable

        if ($file === false) {
            throw new RuntimeException('Unable to create temporary file for parallel analysis.');
        }

        return $file;
    }

    /**
     * @param list<string> $files
     */
    private function cleanup(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}
