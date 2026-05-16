<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Throwable;

use function count;
use function fflush;
use function fwrite;
use function is_array;
use function serialize;
use function sprintf;
use function stream_get_contents;
use function unserialize;

use const STDOUT;

final readonly class ClassNodeWorker
{
    /**
     * @param resource      $input        Readable stream carrying the serialised payload.
     * @param resource      $output       Writable stream for the serialised result.
     * @param resource|null $outputStream Progress stream (defaults to STDOUT).
     */
    public static function run(mixed $input, mixed $output, mixed $outputStream = null): int
    {
        try {
            $payload = unserialize((string) stream_get_contents($input));

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

            $stream = $outputStream ?? STDOUT;

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

            fwrite($output, serialize([
                'nodes' => $nodes,
                'error' => null,
            ]));

            return 0;
        } catch (Throwable $throwable) {
            fwrite($output, serialize([
                'nodes' => [],
                'error' => sprintf('%s: %s', $throwable::class, $throwable->getMessage()),
            ]));

            return 1;
        }
    }
}
