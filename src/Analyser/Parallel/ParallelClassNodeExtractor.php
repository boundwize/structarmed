<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\AnonymousClassNode;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\Analyser\ExtractionResult;
use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use RuntimeException;
use Throwable;

use function array_fill;
use function array_key_exists;
use function array_keys;
use function array_search;
use function arsort;
use function assert;
use function count;
use function dirname;
use function fclose;
use function feof;
use function file_put_contents;
use function filesize;
use function fread;
use function getenv;
use function is_array;
use function is_dir;
use function is_resource;
use function is_string;
use function min;
use function mkdir;
use function proc_close;
use function serialize;
use function sprintf;
use function stream_select;
use function stream_set_blocking;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;
use function unserialize;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

final readonly class ParallelClassNodeExtractor
{
    /**
     * @param array<string, string|list<string>> $layers
     * @param array<string, array<string, mixed>> $layerPatterns
     * @phpstan-param array<string, array{
     *     pattern: string|list<string>,
     *     excludePattern: string|list<string|null>|null
     * }> $layerPatterns
     */
    public function __construct(
        private string $basePath,
        private array $layers,
        private array $layerPatterns,
        private int $workerCount,
        private ?string $cacheDirectory = null,
        private ?string $cacheNamespace = null,
    ) {
    }

    /** @param list<string> $files */
    public function extract(
        array $files,
        ?ProgressHandlerInterface $progressHandler = null,
        bool $withFileAnalysis = true,
    ): ExtractionResult {
        if ($files === []) {
            return new ExtractionResult([], []);
        }

        $totalFiles  = count($files);
        $workerCount = min($this->workerCount, $totalFiles);

        if ($workerCount === 1) {
            return $this->extractInProcess($files, $progressHandler, $withFileAnalysis);
        }

        $script       = dirname(__DIR__, 3) . '/bin/structarmed.php';
        $processes    = [];
        $emitProgress = $progressHandler instanceof ProgressHandlerInterface;
        $environment  = $this->workerEnvironment();

        foreach ($this->buildWorkerBuckets($files, $workerCount) as $chunk) {
            [
                'inputFile'  => $inputFile,
                'outputFile' => $outputFile,
                'stderrFile' => $stderrFile,
            ] = $this->createWorkerFiles();

            file_put_contents($inputFile, serialize([
                'basePath'         => $this->basePath,
                'layers'           => $this->layers,
                'layerPatterns'    => $this->layerPatterns,
                'files'            => $chunk,
                'emitProgress'     => $emitProgress,
                'withFileAnalysis' => $withFileAnalysis,
                'cacheDirectory'   => $this->cacheDirectory,
                'cacheNamespace'   => $this->cacheNamespace,
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
                null,
                $environment,
            );

            if ($process === false) {
                $this->cleanup([$inputFile, $outputFile, $stderrFile]);

                throw new RuntimeException('Unable to start parallel analysis worker.');
            }

            assert(isset($pipes[0]) && isset($pipes[1]));
            fclose($pipes[0]);

            $stdoutPipe = $pipes[1];
            stream_set_blocking($stdoutPipe, false);

            $processes[] = [
                'process'       => $process,
                'files'         => $chunk,
                'filesAdvanced' => 0,
                'inputFile'     => $inputFile,
                'outputFile'    => $outputFile,
                'stderrFile'    => $stderrFile,
                'stdoutPipe'    => $stdoutPipe,
            ];
        }

        $nodes               = [];
        $fileAnalyses        = [];
        $anonymousClassNodes = [];
        $failure             = null;
        $pending             = $processes;
        // stream_select() does not work on process pipes on Windows, so fall back to polling there.
        $useSelect = DIRECTORY_SEPARATOR !== '\\';

        while ($pending !== []) {
            $anyActivity = false;
            $readable    = [];

            foreach ($pending as $key => $workerState) {
                $readable[$key] = $workerState['stdoutPipe'];
            }

            if ($useSelect) {
                $write  = null;
                $except = null;

                // Block until a worker writes progress or exits (pipe EOF is readable too).
                if (@stream_select($readable, $write, $except, 0, 200_000) === false) {
                    foreach ($pending as $key => $workerState) {
                        $readable[$key] = $workerState['stdoutPipe'];
                    }
                }
            }

            foreach (array_keys($readable) as $key) {
                if (! array_key_exists($key, $pending)) {
                    continue;
                }

                $stdoutPipe = $pending[$key]['stdoutPipe'];

                $data = fread($stdoutPipe, 8192);
                if ($data !== false && $data !== '') {
                    $count           = substr_count($data, "\n");
                    $workerFileCount = count($pending[$key]['files']);
                    for ($i = 0; $i < $count; $i++) {
                        $fileIdx = $pending[$key]['filesAdvanced'];
                        if ($fileIdx < $workerFileCount) {
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

                $procResource = $pending[$key]['process'];
                assert(is_resource($procResource));
                $exitCode = proc_close($procResource);

                try {
                    // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
                    $result = unserialize((string) file_get_contents($pending[$key]['outputFile']));
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
                        $stderr = (string) file_get_contents($pending[$key]['stderrFile']);
                        // phpcs:enable

                        throw new RuntimeException(sprintf(
                            "Parallel analysis worker failed: %s%s",
                            $error ?? 'unknown error',
                            $stderr !== '' ? "\n" . $stderr : ''
                        ));
                    }

                    /** @var list<ClassNode> $workerNodes */
                    $workerNodes = $result['nodes'];

                    foreach ($workerNodes as $workerNode) {
                        $nodes[] = $workerNode;
                    }

                    $workerFileAnalyses = $result['fileAnalyses'] ?? [];

                    if (! is_array($workerFileAnalyses)) {
                        throw new RuntimeException('Parallel analysis worker returned invalid file analyses.');
                    }

                    foreach ($workerFileAnalyses as $file => $fileAnalysis) {
                        if (! is_string($file) || ! $fileAnalysis instanceof FileAnalysis) {
                            throw new RuntimeException('Parallel analysis worker returned invalid file analyses.');
                        }

                        $fileAnalyses[$file] = $fileAnalysis;
                    }

                    $workerAnonClassNodes = $result['anonymousClassNodes'] ?? [];

                    if (! is_array($workerAnonClassNodes)) {
                        throw new RuntimeException(
                            'Parallel analysis worker returned invalid anonymous class nodes.'
                        );
                    }

                    foreach ($workerAnonClassNodes as $workerAnonClassNode) {
                        if (! $workerAnonClassNode instanceof AnonymousClassNode) {
                            throw new RuntimeException(
                                'Parallel analysis worker returned invalid anonymous class nodes.'
                            );
                        }

                        $anonymousClassNodes[] = $workerAnonClassNode;
                    }
                } catch (RuntimeException $runtimeException) {
                    $failure ??= $runtimeException->getMessage();
                } finally {
                    $this->cleanup([
                        $pending[$key]['inputFile'],
                        $pending[$key]['outputFile'],
                        $pending[$key]['stderrFile'],
                    ]);
                }

                unset($pending[$key]);
                $anyActivity = true;
            }

            if (! $useSelect && ! $anyActivity) {
                usleep(5000);
            }
        }

        if ($failure !== null) {
            throw new RuntimeException($failure);
        }

        return new ExtractionResult($nodes, $fileAnalyses, $anonymousClassNodes);
    }

    /**
     * Analysing a single bucket in a worker process would only add the PHP boot cost
     * (autoloading, process spawn) on top of the same sequential work, so run it inline.
     * This is the common path for incremental runs where only a few files miss the cache.
     *
     * @param list<string> $files
     */
    private function extractInProcess(
        array $files,
        ?ProgressHandlerInterface $progressHandler,
        bool $withFileAnalysis,
    ): ExtractionResult {
        try {
            $layerResolver = ChainLayerResolver::fromLayerConfig($this->layers, $this->basePath, $this->layerPatterns);

            $extractionResult = (new ClassNodeExtractor($layerResolver))->extract(
                $files,
                $progressHandler,
                $withFileAnalysis,
            );

            if ($this->cacheDirectory !== null && $this->cacheNamespace !== null) {
                (new AnalysisResultCache($this->basePath, $this->cacheDirectory))
                    ->storeExtractionResult($files, $extractionResult, $this->cacheNamespace);
            }

            return $extractionResult;
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf(
                'Parallel analysis worker failed: %s: %s',
                $throwable::class,
                $throwable->getMessage(),
            ), 0, $throwable);
        }
    }

    /**
     * Workers inherit the parent environment; when Xdebug is loaded it would slow every
     * worker down considerably, so it is switched off unless the user opted in explicitly
     * via the XDEBUG_MODE environment variable.
     *
     * @return array<string, string>|null
     */
    private function workerEnvironment(): ?array
    {
        if (getenv('XDEBUG_MODE') !== false) {
            return null;
        }

        $environment                = getenv();
        $environment['XDEBUG_MODE'] = 'off';

        return $environment;
    }

    /**
     * Distributes files across worker buckets using the LPT (Longest Processing Time First) algorithm:
     * sort files largest-first, then assign each file to the worker with the smallest total byte load so far.
     * This minimises the gap between the slowest and fastest worker (makespan), giving near-optimal balance
     * even when one file is much larger than the rest.
     *
     * @param list<string> $files
     * @return list<list<string>>
     */
    private function buildWorkerBuckets(array $files, int $workerCount): array
    {
        $fileSizes = [];
        foreach ($files as $file) {
            $fileSizes[$file] = @filesize($file) ?: 0;
        }

        arsort($fileSizes);

        assert($workerCount > 0);
        $buckets     = array_fill(0, $workerCount, []);
        $bucketSizes = array_fill(0, $workerCount, 0);

        foreach (array_keys($fileSizes) as $file) {
            $minIdx                = (int) array_search(min($bucketSizes), $bucketSizes, true);
            $buckets[$minIdx][]    = $file;
            $bucketSizes[$minIdx] += $fileSizes[$file];
        }

        return $buckets;
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
