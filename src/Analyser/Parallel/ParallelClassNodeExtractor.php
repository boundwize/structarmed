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
use function array_merge;
use function array_values;
use function assert;
use function ceil;
use function count;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_resource;
use function is_string;
use function max;
use function min;
use function proc_close;
use function serialize;
use function sprintf;
use function sqrt;
use function str_replace;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;
use function unserialize;
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
    ) {
    }

    /**
     * @param list<array{file: string, size: int}> $files
     * @return list<ClassNode>
     */
    public function extract(array $files, ?ProgressHandlerInterface $progressHandler = null): array
    {
        if ($files === []) {
            return [];
        }

        $workerCount = min($this->workerCount, count($files));
        $script      = dirname(__DIR__, 3) . '/bin/structarmed.php';

        $nodes   = [];
        $failure = null;

        foreach ($this->workerWaves($this->buildQueue($files, $workerCount), $workerCount) as $wave) {
            $processes = [];
            foreach ($wave as $chunk) {
                try {
                    $processes[] = $this->spawnWorker($chunk, $script);
                } catch (RuntimeException $runtimeException) {
                    foreach ($processes as $process) {
                        proc_close($process['process']);
                        $this->cleanup($this->workerFiles($process));
                    }

                    throw $runtimeException;
                }
            }

            foreach ($processes as $process) {
                $exitCode = proc_close($process['process']);

                try {
                    $this->advanceProgress($process, $progressHandler);
                    $resultBuffer  = (string) file_get_contents($process['outputFile']);
                    $stderrContent = str_replace(
                        ClassNodeWorker::PROGRESS_MARKER,
                        '',
                        (string) file_get_contents($process['stderrFile']),
                    );
                    $result        = @unserialize($resultBuffer);

                    if (
                        ! is_array($result)
                        || ! isset($result['nodes'])
                        || ! is_array($result['nodes'])
                        || ! array_key_exists('error', $result)
                    ) {
                        throw new RuntimeException(sprintf(
                            "Parallel analysis worker returned an invalid payload.%s",
                            $stderrContent !== '' ? "\n" . $stderrContent : '',
                        ));
                    }

                    $error = $result['error'];

                    if ($error !== null && ! is_string($error)) {
                        throw new RuntimeException('Parallel analysis worker returned an invalid error payload.');
                    }

                    if ($error !== null || $exitCode !== 0) {
                        throw new RuntimeException(sprintf(
                            "Parallel analysis worker failed: %s%s",
                            $error ?? 'unknown error',
                            $stderrContent !== '' ? "\n" . $stderrContent : ''
                        ));
                    }

                    /** @var list<ClassNode> $workerNodes */
                    $workerNodes = $result['nodes'];
                    $nodes       = array_merge($nodes, $workerNodes);
                } catch (RuntimeException $runtimeException) {
                    $failure ??= $runtimeException->getMessage();
                } finally {
                    $this->cleanup($this->workerFiles($process));
                }
            }

            if ($failure !== null) {
                throw new RuntimeException($failure);
            }
        }

        return $nodes;
    }

    /**
     * @param array{files: list<string>, stderrFile: string} $process
     */
    private function advanceProgress(array $process, ?ProgressHandlerInterface $progressHandler): void
    {
        if (! $progressHandler instanceof ProgressHandlerInterface) {
            return;
        }

        $progressContent = (string) file_get_contents($process['stderrFile']);
        $progressCount   = substr_count($progressContent, ClassNodeWorker::PROGRESS_MARKER);

        for ($i = 0; $i < $progressCount; $i++) {
            if (! isset($process['files'][$i])) {
                return;
            }

            $progressHandler->advance($process['files'][$i]);
        }
    }

    /**
     * Distributes files across bounded batches using the Longest
     * Processing Time (LPT) algorithm: sort by size descending, then assign each
     * file to the least-loaded bucket. The queue is processed in worker-count
     * waves so individual batches stay smaller without starting too many workers
     * at once.
     *
     * @param list<array{file: string, size: int}> $files
     * @return list<list<string>>
     */
    private function buildQueue(array $files, int $workerCount): array
    {
        usort($files, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        $fileCount  = count($files);
        $batchCount = min(
            $fileCount,
            max($workerCount, (int) ceil(sqrt($fileCount))),
        );

        /** @var array<int, array{files: list<string>, load: int}> $buckets */
        $buckets = array_fill(0, $batchCount, ['files' => [], 'load' => 0]);

        foreach ($files as ['file' => $file, 'size' => $size]) {
            $minIdx = 0;
            for ($i = 1; $i < $batchCount; $i++) {
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
     * @param list<list<string>> $queue
     * @return list<list<list<string>>>
     */
    private function workerWaves(array $queue, int $workerCount): array
    {
        $waves = [];
        $wave  = [];

        foreach ($queue as $chunk) {
            $wave[] = $chunk;

            if (count($wave) < $workerCount) {
                continue;
            }

            $waves[] = $wave;
            $wave    = [];
        }

        if ($wave !== []) {
            $waves[] = $wave;
        }

        return $waves;
    }

    /**
     * @param list<string> $chunk
     * @return array{
     *      process: resource,
     *      files: list<string>,
     *      inputFile: string,
     *      outputFile: string,
     *      stderrFile: string
     * }
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
                1 => ['file', $stderrFile, 'a'],
                2 => ['file', $stderrFile, 'a'],
            ],
            $pipes,
        );

        if ($process === false) {
            $this->cleanup([$inputFile, $outputFile, $stderrFile]);

            throw new RuntimeException('Unable to start parallel analysis worker.');
        }

        assert(isset($pipes[0]));
        fclose($pipes[0]);

        assert(is_resource($process));

        return [
            'process'    => $process,
            'files'      => $chunk,
            'inputFile'  => $inputFile,
            'outputFile' => $outputFile,
            'stderrFile' => $stderrFile,
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
        $dir = sys_get_temp_dir();

        // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
        // to allow tests to mock filesystem edge cases in this namespace
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = tempnam($dir, 'structarmed-worker-');
        // phpcs:enable

        if ($file === false) {
            throw new RuntimeException('Unable to create temporary file for parallel analysis.');
        }

        return $file;
    }

    /**
     * @param array{inputFile: string, outputFile: string, stderrFile: string} $process
     * @return list<string>
     */
    private function workerFiles(array $process): array
    {
        return [
            $process['inputFile'],
            $process['outputFile'],
            $process['stderrFile'],
        ];
    }

    /** @param list<string> $files */
    private function cleanup(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}
