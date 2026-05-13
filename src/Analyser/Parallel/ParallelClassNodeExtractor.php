<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\ClassNameRegexLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use RuntimeException;

use function array_chunk;
use function array_key_exists;
use function array_merge;
use function ceil;
use function count;
use function dirname;
use function fclose;
use function file_put_contents;
use function function_exists;
use function is_array;
use function is_dir;
use function is_string;
use function max;
use function min;
use function mkdir;
use function proc_close;
use function serialize;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;
use function unserialize;

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
        if ($files === [] || $this->workerCount <= 1 || ! function_exists('proc_open')) {
            return (new ClassNodeExtractor($this->layerResolver()))->extract($files, $progressHandler);
        }

        $workerCount = min($this->workerCount, count($files));
        $chunkSize   = (int) ceil(count($files) / $workerCount);
        $script      = dirname(__DIR__, 3) . '/bin/structarmed.php';
        $processes   = [];

        foreach (array_chunk($files, max(1, $chunkSize)) as $chunk) {
            [
                'inputFile'  => $inputFile,
                'outputFile' => $outputFile,
                'stdoutFile' => $stdoutFile,
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
                    1 => ['file', $stdoutFile, 'w'],
                    2 => ['file', $stderrFile, 'w'],
                ],
                $pipes,
            );

            if ($process === false) {
                $this->cleanup([$inputFile, $outputFile, $stdoutFile, $stderrFile]);

                throw new RuntimeException('Unable to start parallel analysis worker.');
            }

            fclose($pipes[0]);

            $processes[] = [
                'process'    => $process,
                'files'      => $chunk,
                'inputFile'  => $inputFile,
                'outputFile' => $outputFile,
                'stdoutFile' => $stdoutFile,
                'stderrFile' => $stderrFile,
            ];
        }

        $nodes   = [];
        $failure = null;

        foreach ($processes as $process) {
            $resource = $process['process'];
            $exitCode = proc_close($resource);
            // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
            $result = unserialize((string) file_get_contents($process['outputFile']));
            // phpcs:enable

            try {
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
                    $stderr = (string) file_get_contents($process['stderrFile']);
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

                foreach ($process['files'] as $file) {
                    $progressHandler?->advance($file);
                }
            } catch (RuntimeException $runtimeException) {
                $failure ??= $runtimeException->getMessage();
            } finally {
                $this->cleanup([
                    $process['inputFile'],
                    $process['outputFile'],
                    $process['stdoutFile'],
                    $process['stderrFile'],
                ]);
            }
        }

        if ($failure !== null) {
            throw new RuntimeException($failure);
        }

        return $nodes;
    }

    /**
     * @return array{inputFile: string, outputFile: string, stdoutFile: string, stderrFile: string}
     */
    private function createWorkerFiles(): array
    {
        return [
            'inputFile'  => $this->temporaryFile(),
            'outputFile' => $this->temporaryFile(),
            'stdoutFile' => $this->temporaryFile(),
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

    private function layerResolver(): ChainLayerResolver
    {
        return $this->layerPatterns !== []
            ? new ChainLayerResolver(
                new ClassNameRegexLayerResolver($this->layerPatterns),
                new NamespaceLayerResolver($this->layers, $this->basePath)
            )
            : new ChainLayerResolver(
                new NamespaceLayerResolver($this->layers, $this->basePath)
            );
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
