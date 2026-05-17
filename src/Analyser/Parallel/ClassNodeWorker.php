<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Throwable;

use function base64_encode;
use function count;
use function fflush;
use function fwrite;
use function is_array;
use function serialize;
use function sprintf;
use function stream_get_contents;
use function unserialize;

use const STDIN;
use const STDOUT;

final readonly class ClassNodeWorker
{
    /** @param resource|null $outputStream @param resource|null $inputStream */
    public static function run(mixed $outputStream = null, mixed $inputStream = null): int
    {
        $stream = $outputStream ?? STDOUT;

        try {
            $payload = unserialize((string) stream_get_contents($inputStream ?? STDIN));

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

            $layerResolver = ChainLayerResolver::fromLayerConfig($layers, $basePath, $layerPatterns);

            $progressHandler = new class ($stream) implements ProgressHandlerInterface {
                /** @param resource $stream */
                public function __construct(private readonly mixed $stream)
                {
                }

                public function start(int $total): void
                {
                }

                public function advance(string $file): void
                {
                    fwrite($this->stream, "\n");
                    fflush($this->stream);
                }

                public function finish(): void
                {
                }
            };

            $progressHandler->start(count($files));
            $nodes = (new ClassNodeExtractor($layerResolver))->extract($files, $progressHandler);
            $progressHandler->finish();

            fwrite($stream, base64_encode(serialize(['nodes' => $nodes, 'error' => null])) . "\n");
            fflush($stream);

            return 0;
        } catch (Throwable $throwable) {
            fwrite($stream, base64_encode(serialize([
                'nodes' => [],
                'error' => sprintf('%s: %s', $throwable::class, $throwable->getMessage()),
            ])) . "\n");
            fflush($stream);

            return 1;
        }
    }
}
