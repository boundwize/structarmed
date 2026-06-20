<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Throwable;

use function file_get_contents;
use function file_put_contents;
use function is_array;
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

            $result = (new ClassNodeExtractor($layerResolver))->extract($files, $progressHandler);

            file_put_contents($outputFile, serialize([
                'nodes'        => $result->classNodes,
                'fileAnalyses' => $result->fileAnalyses,
                'error'        => null,
            ]));

            return 0;
        } catch (Throwable $throwable) {
            file_put_contents($outputFile, serialize([
                'nodes'        => [],
                'fileAnalyses' => [],
                'error'        => sprintf('%s: %s', $throwable::class, $throwable->getMessage()),
            ]));

            return 1;
        }
    }
}
