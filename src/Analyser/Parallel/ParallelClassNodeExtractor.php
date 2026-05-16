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
use function filesize;
use function fread;
use function fwrite;
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
                $resultPipe = $active[$key]['resultPipe'];

                // Non-blocking drain of result pipe to prevent deadlock when
                // the OS pipe buffer fills before stdout reaches EOF.
                $chunk = fread($resultPipe, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $active[$key]['resultBuffer'] .= $chunk;
                    $anyActivity                   = true;
                }

                $stderrPipe = $active[$key]['stderrPipe'];

                $data = fread($stderrPipe, 8192);
                if ($data !== false && $data !== '') {
                    $active[$key]['stderrBuffer'] .= $data;
                    $count                         = substr_count($data, ClassNodeWorker::PROGRESS_MARKER);
                    for ($i = 0; $i < $count; $i++) {
                        $fileIdx = $active[$key]['filesAdvanced'];
                        if ($fileIdx < count($active[$key]['files'])) {
                            $progressHandler?->advance($active[$key]['files'][$fileIdx]);
                            $active[$key]['filesAdvanced']++;
                        }
                    }

                    $anyActivity = true;
                }

                $decodedResult = $this->tryDecodeWorkerResult($active[$key]['resultBuffer']);

                if ($decodedResult === null && (! feof($resultPipe) || ! feof($stderrPipe))) {
                    continue;
                }

                $resultBuffer = $active[$key]['resultBuffer'];
                fclose($resultPipe);

                $stderrContent = str_replace(
                    ClassNodeWorker::PROGRESS_MARKER,
                    '',
                    $active[$key]['stderrBuffer'],
                );
                fclose($stderrPipe);

                // proc_close() has not been called yet so waitpid() inside it correctly
                // returns the real exit code (no double-waitpid race with proc_get_status).
                $exitCode = proc_close($active[$key]['process']);

                try {
                    $result = $decodedResult ?? @unserialize($resultBuffer);

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
                }

                unset($active[$key]);
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
     * @return array<mixed>|null
     */
    private function tryDecodeWorkerResult(string $resultBuffer): ?array
    {
        if ($resultBuffer === '') {
            return null;
        }

        $result = @unserialize($resultBuffer);

        return is_array($result) ? $result : null;
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
     * @return array{
     *      process: resource,
     *      files: list<string>,
     *      filesAdvanced: int,
     *      stderrPipe: resource,
     *      resultPipe: resource,
     *      resultBuffer: string,
     *      stderrBuffer: string
     * }
     */
    private function spawnWorker(array $chunk, string $script): array
    {
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

        assert(isset($pipes[0]) && isset($pipes[1]) && isset($pipes[2]));

        fwrite($pipes[0], serialize([
            'basePath'      => $this->basePath,
            'layers'        => $this->layers,
            'layerPatterns' => $this->layerPatterns,
            'files'         => $chunk,
        ]));
        fclose($pipes[0]);

        $resultPipe = $pipes[1];
        $stderrPipe = $pipes[2];

        assert(is_resource($process));
        assert(is_resource($resultPipe));
        assert(is_resource($stderrPipe));

        stream_set_blocking($resultPipe, false);
        stream_set_blocking($stderrPipe, false);

        return [
            'process'       => $process,
            'files'         => $chunk,
            'filesAdvanced' => 0,
            'resultPipe'    => $resultPipe,
            'stderrPipe'    => $stderrPipe,
            'resultBuffer'  => '',
            'stderrBuffer'  => '',
        ];
    }
}
