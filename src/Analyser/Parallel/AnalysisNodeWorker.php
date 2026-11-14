<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\AnalysisNodeExtractor;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Throwable;

use function file_put_contents;
use function is_array;
use function serialize;
use function sprintf;

use const STDOUT;

/**
 * @internal
 */
final readonly class AnalysisNodeWorker
{
    /** @param resource|null $outputStream */
    public static function run(mixed $payload, string $outputFile, mixed $outputStream = null): int
    {
        try {
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
            /** @var bool $withSignatures */
            $withSignatures = $payload['withSignatures'] ?? false;
            /** @var bool $withFileReferences */
            $withFileReferences = $payload['withFileReferences'] ?? true;
            /**
             * @var array<string, array{
             *     pattern: string|list<string>,
             *     excludePattern: string|list<string|null>|null
             * }> $layerPatterns
             */
            $layerPatterns = $payload['layerPatterns'];
            /** @var array<string, list<string>> $layerExcludePaths */
            $layerExcludePaths = $payload['layerExcludePaths'] ?? [];
            /** @var list<string> $files */
            $files = $payload['files'];

            $layerResolver = ChainLayerResolver::fromLayerConfig(
                $layers,
                $basePath,
                $layerPatterns,
                $layerExcludePaths
            );

            $stream = $outputStream ?? STDOUT;

            $progressHandler = $emitProgress ? new WorkerProgressHandler($stream, $files) : null;

            $cache = $payload['cache'] ?? null;
            /** @var string $cacheNamespace */
            $cacheNamespace = $payload['cacheNamespace'] ?? '';

            $result = (new AnalysisNodeExtractor(
                $layerResolver,
                analysisResultCache: $cache instanceof AnalysisResultCache ? $cache : null,
                analysisNodeCacheNamespace: $cacheNamespace,
            ))->extract($files, $progressHandler, $withFileAnalysis, $withSignatures, $withFileReferences);

            file_put_contents($outputFile, serialize([
                'nodes'                  => $result->classNodes,
                'fileAnalyses'           => $result->fileAnalyses,
                'anonymousClassNodes'    => $result->anonymousClassNodes,
                'fileReferences'         => $result->fileReferences,
                'fileInstantiations'     => $result->fileInstantiations,
                'functionNodes'          => $result->functionNodes,
                'anonymousFunctionNodes' => $result->anonymousFunctionNodes,
                'error'                  => null,
            ]));

            return 0;
        } catch (Throwable $throwable) {
            file_put_contents($outputFile, serialize([
                'nodes'                  => [],
                'fileAnalyses'           => [],
                'anonymousClassNodes'    => [],
                'fileReferences'         => [],
                'fileInstantiations'     => [],
                'functionNodes'          => [],
                'anonymousFunctionNodes' => [],
                'error'                  => sprintf('%s: %s', $throwable::class, $throwable->getMessage()),
            ]));

            return 1;
        }
    }
}
