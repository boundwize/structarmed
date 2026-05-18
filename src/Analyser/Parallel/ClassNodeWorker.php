<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
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

            if (! is_resource($stream)) {
                throw new WorkerFailedException('Invalid worker socket stream.');
            }

            if ($socketStream === null) {
                WorkerPayloadSocket::writePayload($stream, ['workerId' => $workerId]);
            }

            $payload = WorkerPayloadSocket::readPayload($stream);

            if ($socketStream !== null || self::isLegacyPayload($payload)) {
                $progressStream = $outputStream ?? STDOUT;

                if (! is_resource($progressStream)) {
                    throw new WorkerFailedException('Invalid worker progress stream.');
                }

                return self::runLegacyBatch($payload, $progressStream, $stream);
            }

            return self::runBatchLoop($stream, $payload);
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
    private static function runLegacyBatch(array $payload, mixed $progressStream, mixed $stream): int
    {
        try {
            $nodes = self::extractNodes(
                $payload,
                new class ($progressStream) implements ProgressHandlerInterface {
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
     * @param resource $stream
     * @param array<mixed> $payload
     */
    private static function runBatchLoop(mixed $stream, array $payload): int
    {
        $currentPayload = $payload;

        while (true) {
            $type = $currentPayload['type'] ?? null;

            if ($type === 'stop') {
                fclose($stream);

                return 0;
            }

            try {
                if ($type !== 'batch') {
                    throw new WorkerFailedException('Invalid worker payload.');
                }

                $nodes = self::extractNodes(
                    $currentPayload,
                    new class ($stream) implements ProgressHandlerInterface {
                        /** @param resource $stream */
                        public function __construct(private readonly mixed $stream)
                        {
                        }

                        public function start(int $total): void
                        {
                        }

                        public function advance(string $file): void
                        {
                            WorkerPayloadSocket::writePayload($this->stream, ['type' => 'progress']);
                        }

                        public function finish(): void
                        {
                        }
                    },
                );

                WorkerPayloadSocket::writePayload($stream, [
                    'type'  => 'result',
                    'nodes' => $nodes,
                    'error' => null,
                ]);
            } catch (Throwable $throwable) {
                WorkerPayloadSocket::writePayload($stream, [
                    'type'  => 'result',
                    'nodes' => [],
                    'error' => sprintf('%s: %s', $throwable::class, $throwable->getMessage()),
                ]);

                fclose($stream);

                return 1;
            }

            $currentPayload = WorkerPayloadSocket::readPayload($stream);
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

        /** @param array<mixed> $payload */
    private static function isLegacyPayload(array $payload): bool
    {
        return ! isset($payload['type'])
            && isset($payload['basePath'], $payload['layers'], $payload['layerPatterns'], $payload['files']);
    }
}
