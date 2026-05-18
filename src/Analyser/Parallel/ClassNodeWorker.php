<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Throwable;

use function count;
use function fclose;
use function fflush;
use function fwrite;
use function is_array;
use function is_resource;
use function is_string;
use function sprintf;

use const STDOUT;

final readonly class ClassNodeWorker
{
    /** @param resource|null $outputStream */
    public static function run(string $address, string $workerId = '', mixed $outputStream = null, mixed $socketStream = null): int
    {
        $stream = $socketStream;

        try {
            $stream ??= WorkerPayloadSocket::connect($address);

            if ($socketStream === null) {
                WorkerPayloadSocket::writePayload($stream, ['workerId' => $workerId]);
            }

            $payload = WorkerPayloadSocket::readPayload($stream);

            if (
                ! isset($payload['basePath'], $payload['layers'], $payload['layerPatterns'], $payload['files'])
                || ! is_string($payload['basePath'])
                || ! is_array($payload['layers'])
                || ! is_array($payload['layerPatterns'])
                || ! is_array($payload['files'])
            ) {
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

            $progressStream = $outputStream ?? STDOUT;

            $progressHandler = new class ($progressStream) implements ProgressHandlerInterface {
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

            WorkerPayloadSocket::writePayload($stream, [
                'nodes' => $nodes,
                'error' => null,
            ]);

            fclose($stream);

            return 0;
        } catch (Throwable $throwable) {
            if (is_resource($stream)) {
                WorkerPayloadSocket::writePayload($stream, [
                    'nodes' => [],
                    'error' => sprintf('%s: %s', $throwable::class, $throwable->getMessage()),
                ]);

                fclose($stream);
            }

            return 1;
        }
    }
}
