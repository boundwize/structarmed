<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use RuntimeException;

use function assert;
use function fclose;
use function fread;
use function fwrite;
use function is_array;
use function is_int;
use function pack;
use function serialize;
use function sprintf;
use function stream_get_meta_data;
use function strlen;
use function substr;
use function unserialize;

use const STREAM_CLIENT_CONNECT;

final class WorkerPayloadSocket
{
    private const WRITE_CHUNK_SIZE = 8192;

    /**
     * @param resource $stream
     * @param array<mixed> $payload
     */
    public static function writePayload(mixed $stream, array $payload): void
    {
        $data = serialize($payload);

        self::writeAll($stream, pack('N', strlen($data)));
        self::writeAll($stream, $data);
    }

    /**
     * @param resource $stream
     * @return array<mixed>
     */
    public static function readPayload(mixed $stream): array
    {
        $header = self::readExact($stream, 4);
        // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
        // to allow tests to mock unpack in this namespace
        $decodedHeader = unpack('Nlength', $header);
        // phpcs:enable

        if (! is_array($decodedHeader) || ! isset($decodedHeader['length'])) {
            throw new RuntimeException('Unable to read worker payload header.');
        }

        $length = $decodedHeader['length'];

        if (! is_int($length) || $length < 0) {
            throw new RuntimeException('Unable to read worker payload header.');
        }

        $payload = unserialize(self::readExact($stream, $length));

        if (! is_array($payload)) {
            throw new RuntimeException('Invalid worker payload.');
        }

        return $payload;
    }

    /** @return resource */
    public static function connect(string $address, float $timeoutSeconds = 5.0): mixed
    {
        $errorCode    = 0;
        $errorMessage = '';

        // phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFallbackGlobalName
        // to allow tests to mock stream_socket_client in this namespace
        $stream = stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );
        // phpcs:enable

        if ($stream === false) {
            throw new RuntimeException(sprintf(
                'Unable to connect to parallel analysis worker socket [%s]: %s',
                $address,
                $errorMessage !== '' ? $errorMessage : 'unknown error'
            ));
        }

        return $stream;
    }

    /** @param resource $stream */
    public static function closeWrite(mixed $stream): void
    {
        $metadata = stream_get_meta_data($stream);

        if ($metadata['mode'] === 'r') {
            return;
        }

        fclose($stream);
    }

    /**
     * @param resource $stream
     */
    private static function writeAll(mixed $stream, string $data): void
    {
        $length = strlen($data);

        if ($length === 0) {
            return;
        }

        $written = fwrite($stream, $data);

        if ($written === $length) {
            return;
        }

        if ($written === false) {
            throw new RuntimeException('Unable to write worker payload.');
        }

        $offset = $written;
        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset, self::WRITE_CHUNK_SIZE));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write worker payload.');
            }

            $offset += $written;
        }
    }

    /**
     * @param resource $stream
     */
    private static function readExact(mixed $stream, int $length): string
    {
        assert($length > 0);

        $chunk = fread($stream, $length);

        if ($chunk !== false && strlen($chunk) === $length) {
            return $chunk;
        }

        if ($chunk === false) {
            throw new RuntimeException('Unable to read worker payload.');
        }

        $buffer = $chunk;

        while (strlen($buffer) < $length) {
            $remaining = $length - strlen($buffer);
            assert($remaining > 0);

            $chunk = fread($stream, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Unable to read worker payload.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
