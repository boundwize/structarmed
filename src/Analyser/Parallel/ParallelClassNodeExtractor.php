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
use function file_get_contents;
use function file_put_contents;
use function fread;
use function is_array;
use function is_resource;
use function is_string;
use function min;
use function proc_close;
use function serialize;
use function sprintf;
use function str_replace;
use function stream_set_blocking;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;
use function unserialize;
use function usleep;
use function usort;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

/**
 * @phpstan-type WorkerProcess array{
 *     process: resource,
 *     files: list<string>,
 *     inputFile: string,
 *     outputFile: string,
 *     stderrFile: string,
 *     stdoutPipe?: resource
 * }
 * @phpstan-type WorkerProcessWithPipe array{
 *     process: resource,
 *     files: list<string>,
 *     inputFile: string,
 *     outputFile: string,
 *     stderrFile: string,
 *     stdoutPipe: resource
 * }
 */
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
        private ?bool $usesProgressPipe = null,
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
        $processes   = [];

        foreach ($this->buildQueue($files, $workerCount) as $chunk) {
            try {
                $processes[] = $this->spawnWorker($chunk, $script);
            } catch (RuntimeException $runtimeException) {
                foreach ($processes as $process) {
                    $this->closeWorker($process);
                    $this->cleanup($this->workerFiles($process));
                }

                throw $runtimeException;
            }
        }

        $nodes         = [];
        $failure       = null;
        $pending       = $processes;
        $advancedFiles = array_fill(0, count($processes), 0);

        while ($pending !== []) {
            $anyActivity = false;

            foreach (array_keys($pending) as $key) {
                if (isset($pending[$key]['stdoutPipe'])) {
                    $progress            = $this->advanceProgressFromPipe(
                        $pending[$key],
                        $advancedFiles[$key],
                        $progressHandler,
                    );
                    $advancedFiles[$key] = $progress['advancedFiles'];
                    $hasActivity         = $progress['hasActivity'];
                    $anyActivity         = $hasActivity || $anyActivity;

                    if (! feof($pending[$key]['stdoutPipe'])) {
                        continue;
                    }

                    fclose($pending[$key]['stdoutPipe']);
                }

                $procResource = $pending[$key]['process'];
                assert(is_resource($procResource));
                $exitCode = proc_close($procResource);

                if (! isset($pending[$key]['stdoutPipe'])) {
                    $advancedFiles[$key] = $this->advanceProgressFromFile(
                        $pending[$key],
                        $advancedFiles[$key],
                        $progressHandler,
                    );
                }

                try {
                    /** @var list<ClassNode> $workerNodes */
                    $workerNodes = $this->workerNodes($pending[$key], $exitCode);
                    $nodes       = array_merge($nodes, $workerNodes);
                } catch (RuntimeException $runtimeException) {
                    $failure ??= $runtimeException->getMessage();
                } finally {
                    $this->cleanup($this->workerFiles($pending[$key]));
                }

                unset($pending[$key]);
                unset($advancedFiles[$key]);
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
     * @param array<string, mixed> $process
     * @phpstan-param WorkerProcessWithPipe $process
     * @return array{advancedFiles: int, hasActivity: bool}
     */
    private function advanceProgressFromPipe(
        array $process,
        int $advancedFiles,
        ?ProgressHandlerInterface $progressHandler
    ): array {
        $data = fread($process['stdoutPipe'], 8192);

        if ($data === false || $data === '') {
            return [
                'advancedFiles' => $advancedFiles,
                'hasActivity'   => false,
            ];
        }

        return [
            'advancedFiles' => $this->advanceProgressMarkers(
                $process['files'],
                $advancedFiles,
                substr_count($data, ClassNodeWorker::PROGRESS_MARKER),
                $progressHandler,
            ),
            'hasActivity'   => true,
        ];
    }

    /**
     * @param array<string, mixed> $process
     * @phpstan-param WorkerProcess $process
     */
    private function advanceProgressFromFile(
        array $process,
        int $advancedFiles,
        ?ProgressHandlerInterface $progressHandler
    ): int {
        $progressContent = (string) file_get_contents($process['stderrFile']);

        return $this->advanceProgressMarkers(
            $process['files'],
            $advancedFiles,
            substr_count($progressContent, ClassNodeWorker::PROGRESS_MARKER),
            $progressHandler,
        );
    }

    /**
     * @param list<string> $files
     */
    private function advanceProgressMarkers(
        array $files,
        int $advancedFiles,
        int $progressCount,
        ?ProgressHandlerInterface $progressHandler
    ): int {
        if (! $progressHandler instanceof ProgressHandlerInterface) {
            return $advancedFiles;
        }

        for ($i = 0; $i < $progressCount; $i++) {
            if (! isset($files[$advancedFiles])) {
                return $advancedFiles;
            }

            $progressHandler->advance($files[$advancedFiles]);
            $advancedFiles++;
        }

        return $advancedFiles;
    }

    /**
     * @param array<string, mixed> $process
     * @phpstan-param WorkerProcess $process
     * @return list<ClassNode>
     */
    private function workerNodes(array $process, int $exitCode): array
    {
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

        return $workerNodes;
    }

    /**
     * Distributes files across worker batches using the Longest
     * Processing Time (LPT) algorithm: sort by size descending, then assign each
     * file to the least-loaded bucket. This keeps the number of PHP worker
     * launches bounded to the configured worker count while still avoiding one
     * worker receiving all of the largest files.
     *
     * @param list<array{file: string, size: int}> $files
     * @return list<list<string>>
     */
    private function buildQueue(array $files, int $workerCount): array
    {
        usort($files, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        /** @var array<int, array{files: list<string>, load: int}> $buckets */
        $buckets = array_fill(0, $workerCount, ['files' => [], 'load' => 0]);

        foreach ($files as ['file' => $file, 'size' => $size]) {
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
     * @return array{
     *      process: resource,
     *      files: list<string>,
     *      inputFile: string,
     *      outputFile: string,
     *      stderrFile: string,
     *      stdoutPipe?: resource
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

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $this->usesProgressPipe() ? ['pipe', 'w'] : ['file', $stderrFile, 'a'],
            2 => ['file', $stderrFile, 'a'],
        ];

        // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
        // to avoid error in test that mock it
        $process = proc_open(
        // phpcs:enable
            [PHP_BINARY, $script, '--internal-worker', $inputFile, $outputFile],
            $descriptors,
            $pipes,
        );

        if ($process === false) {
            $this->cleanup([$inputFile, $outputFile, $stderrFile]);

            throw new RuntimeException('Unable to start parallel analysis worker.');
        }

        assert(isset($pipes[0]));
        fclose($pipes[0]);

        assert(is_resource($process));

        $worker = [
            'process'    => $process,
            'files'      => $chunk,
            'inputFile'  => $inputFile,
            'outputFile' => $outputFile,
            'stderrFile' => $stderrFile,
        ];

        if ($this->usesProgressPipe()) {
            assert(isset($pipes[1]));
            stream_set_blocking($pipes[1], false);
            $worker['stdoutPipe'] = $pipes[1];
        }

        return $worker;
    }

    private function usesProgressPipe(): bool
    {
        return $this->usesProgressPipe ?? PHP_OS_FAMILY !== 'Windows';
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
     * @param array<string, mixed> $process
     * @phpstan-param WorkerProcess $process
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

    /**
     * @param array<string, mixed> $process
     * @phpstan-param WorkerProcess $process
     */
    private function closeWorker(array $process): void
    {
        if (isset($process['stdoutPipe'])) {
            fclose($process['stdoutPipe']);
        }

        proc_close($process['process']);
    }

    /** @param list<string> $files */
    private function cleanup(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}
