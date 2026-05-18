<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Throwable;

use function ceil;
use function count;
use function fclose;
use function fflush;
use function fwrite;
use function is_array;
use function is_resource;
use function is_string;
use function max;
use function sprintf;
use function sqrt;
use function str_repeat;

use const STDOUT;

final readonly class ClassNodeWorker
{
    /** @param resource|null $outputStream */
    public static function run(
        string $address,
        string $workerId = '',
        mixed $outputStream = null,
        mixed $socketStream = null
    ): int {
        $stream = $socketStream;

        try {
            $stream ??= WorkerPayloadSocket::connect($address);

            if (! is_resource($stream)) {
                throw new WorkerFailedException('Invalid worker socket stream.');
            }

            if ($socketStream === null) {
                WorkerPayloadSocket::writePayload($stream, ['workerId' => $workerId]);
            }

            $payload = WorkerPayloadSocket::readPayload($stream);

            $progressStream = $outputStream ?? STDOUT;

            if (! is_resource($progressStream)) {
                throw new WorkerFailedException('Invalid worker progress stream.');
            }

            return self::runBatch($payload, $progressStream, $stream);
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

    /**
     * @param array<mixed> $payload
     * @param resource $stream
     * @param resource $progressStream
     */
    private static function runBatch(array $payload, mixed $progressStream, mixed $stream): int
    {
        try {
            $nodes = self::extractNodes(
                $payload,
                new class ($progressStream) implements ProgressHandlerInterface {
                    private int $pendingAdvances = 0;

                    private int $flushEvery = 1;

                    /** @param resource $stream */
                    public function __construct(private readonly mixed $stream)
                    {
                    }

                    public function start(int $total): void
                    {
                        $this->flushEvery = max(1, (int) ceil(sqrt($total)));
                    }

                    public function advance(string $file): void
                    {
                        $this->pendingAdvances++;

                        if ($this->pendingAdvances < $this->flushEvery) {
                            return;
                        }

                        fwrite($this->stream, str_repeat("\n", $this->pendingAdvances));
                        fflush($this->stream);
                        $this->pendingAdvances = 0;
                    }

                    public function finish(): void
                    {
                        if ($this->pendingAdvances === 0) {
                            return;
                        }

                        fwrite($this->stream, str_repeat("\n", $this->pendingAdvances));
                        fflush($this->stream);
                        $this->pendingAdvances = 0;
                    }
                },
            );

            WorkerPayloadSocket::writePayload($stream, [
                'nodes' => $nodes,
                'error' => null,
            ]);

            fclose($stream);

            return 0;
        } catch (Throwable $throwable) {
            WorkerPayloadSocket::writePayload($stream, [
                'nodes' => [],
                'error' => sprintf('%s: %s', $throwable::class, $throwable->getMessage()),
            ]);

            fclose($stream);

            return 1;
        }
    }

    /**
     * @param array<mixed> $payload
     * @return list<ClassNode>
     */
    private static function extractNodes(array $payload, ProgressHandlerInterface $progressHandler): array
    {
        if (
            ! isset($payload['basePath'], $payload['layers'], $payload['layerPatterns'], $payload['files'])
            || ! is_string($payload['basePath'])
            || ! is_array($payload['layers'])
            || ! is_array($payload['layerPatterns'])
            || ! is_array($payload['files'])
        ) {
            throw new WorkerFailedException('Invalid worker payload.');
        }

            $basePath = $payload['basePath'];
            /** @var array<string, string|list<string>> $layers */
            $layers = $payload['layers'];
            /** @var array<string, array{pattern: string, excludePattern: string|null}> $layerPatterns */
            $layerPatterns = $payload['layerPatterns'];
            /** @var list<string> $files */
            $files = $payload['files'];

            $chainLayerResolver = ChainLayerResolver::fromLayerConfig($layers, $basePath, $layerPatterns);

            $progressHandler->start(count($files));
            $nodes = (new ClassNodeExtractor($chainLayerResolver))->extract($files, $progressHandler);
            $progressHandler->finish();

            return $nodes;
    }
}
