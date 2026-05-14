<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\ClassNameRegexLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Throwable;

use function fflush;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function is_array;
use function serialize;
use function sprintf;
use function unserialize;

use const STDOUT;

final readonly class ClassNodeWorker
{
    public static function run(string $inputFile, string $outputFile): int
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
            /** @var array<string, array{pattern: string, excludePattern: string|null}> $layerPatterns */
            $layerPatterns = $payload['layerPatterns'];
            /** @var list<string> $files */
            $files = $payload['files'];

            $layerResolver = $layerPatterns !== []
                ? new ChainLayerResolver(
                    new ClassNameRegexLayerResolver($layerPatterns),
                    new NamespaceLayerResolver($layers, $basePath)
                )
                : new ChainLayerResolver(
                    new NamespaceLayerResolver($layers, $basePath)
                );

            $progressHandler = new class implements ProgressHandlerInterface {
                public function start(int $total): void
                {
                }

                public function advance(string $file): void
                {
                    fwrite(STDOUT, "\n");
                    fflush(STDOUT);
                }

                public function finish(): void
                {
                }
            };

            $nodes = (new ClassNodeExtractor($layerResolver))->extract($files, $progressHandler);

            file_put_contents($outputFile, serialize([
                'nodes' => $nodes,
                'error' => null,
            ]));

            return 0;
        } catch (Throwable $throwable) {
            file_put_contents($outputFile, serialize([
                'nodes' => [],
                'error' => sprintf('%s: %s', $throwable::class, $throwable->getMessage()),
            ]));

            return 1;
        }
    }
}
