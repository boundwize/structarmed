<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Parallel;

use RuntimeException;

use function assert;
use function fclose;
use function fwrite;
use function is_array;
use function pack;
use function serialize;
use function sprintf;
use function stream_get_meta_data;
use function stream_socket_client;
use function strlen;
use function substr;
use function unserialize;
use function unpack;

use const STREAM_CLIENT_CONNECT;

final class WorkerPayloadSocket
{
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
        $decodedHeader = unpack('Nlength', $header);

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
        $errorCode = 0;
        $errorMessage = '';

        $stream = stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

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
        assert(is_array($metadata));

        if (($metadata['mode'] ?? null) === 'r') {
            return;
        }

        fclose($stream);
    }

    /**
     * @param resource $stream
     */
    private static function writeAll(mixed $stream, string $data): void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset));

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
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($stream, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Unable to read worker payload.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}