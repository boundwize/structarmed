<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Throwable;

use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_string;
use function serialize;
use function sprintf;
use function unserialize;

use const STDOUT;

final readonly class ClassNodeWorker
{
    /** @param resource|null $outputStream */
    public static function run(string $inputFile, string $outputFile, mixed $outputStream = null): int
    {
        try {
            $payload = unserialize((string) file_get_contents($inputFile));

            if (! is_array($payload)) {
                throw new WorkerFailedException('Invalid worker payload.');
            }

            /** @var string $basePath */
            $basePath = $payload['basePath'];
            /** @var array<string, string|list<string>> $layers */
            $layers = $payload['layers'];
            /** @var bool $emitProgress */
            $emitProgress = $payload['emitProgress'] ?? true;
            /** @var bool $withFileAnalysis */
            $withFileAnalysis = $payload['withFileAnalysis'] ?? true;
            /**
             * @var array<string, array{
             *     pattern: string|list<string>,
             *     excludePattern: string|list<string|null>|null
             * }> $layerPatterns
             */
            $layerPatterns = $payload['layerPatterns'];
            /** @var list<string> $files */
            $files = $payload['files'];

            $layerResolver = ChainLayerResolver::fromLayerConfig($layers, $basePath, $layerPatterns);

            $stream = $outputStream ?? STDOUT;

            $progressHandler = $emitProgress ? new WorkerProgressHandler($stream) : null;

            $result = (new ClassNodeExtractor($layerResolver))->extract(
                $files,
                $progressHandler,
                $withFileAnalysis,
            );

            $cacheDirectory = $payload['cacheDirectory'] ?? null;
            $cacheNamespace = $payload['cacheNamespace'] ?? null;

            // Writing the per-file cache entries here spreads the JSON serialisation and
            // disk writes across all workers instead of doing them serially afterwards.
            if (is_string($cacheDirectory) && is_string($cacheNamespace)) {
                (new AnalysisResultCache($basePath, $cacheDirectory))
                    ->storeExtractionResult($files, $result, $cacheNamespace);
            }

            file_put_contents($outputFile, serialize([
                'nodes'               => $result->classNodes,
                'fileAnalyses'        => $result->fileAnalyses,
                'anonymousClassNodes' => $result->anonymousClassNodes,
                'error'               => null,
            ]));

            return 0;
        } catch (Throwable $throwable) {
            file_put_contents($outputFile, serialize([
                'nodes'               => [],
                'fileAnalyses'        => [],
                'anonymousClassNodes' => [],
                'error'               => sprintf('%s: %s', $throwable::class, $throwable->getMessage()),
            ]));

            return 1;
        }
    }
}
