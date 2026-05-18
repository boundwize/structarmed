<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\Parallel\WorkerPayloadSocket;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function fclose;
use function file_put_contents;
use function fopen;
use function fwrite;
use function is_resource;
use function pack;
use function rewind;
use function serialize;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(WorkerPayloadSocket::class)]
final class WorkerPayloadSocketTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['mock_stream_socket_client_callback'] = null;
        $GLOBALS['mock_unpack_callback']               = null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['mock_stream_socket_client_callback'] = null;
        $GLOBALS['mock_unpack_callback']               = null;
    }

    public function testPayloadRoundTrip(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);

        WorkerPayloadSocket::writePayload($stream, ['nodes' => [], 'error' => null]);
        rewind($stream);

        $this->assertSame(['nodes' => [], 'error' => null], WorkerPayloadSocket::readPayload($stream));
    }

    public function testReadPayloadThrowsWhenHeaderIsIncomplete(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);

        fwrite($stream, "\x00\x00\x00");
        rewind($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read worker payload.');

        WorkerPayloadSocket::readPayload($stream);
    }

    public function testReadPayloadThrowsWhenHeaderCannotBeDecoded(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);

        fwrite($stream, "\x00\x00\x00\x00");
        rewind($stream);

        $GLOBALS['mock_unpack_callback'] = static fn (string $format, string $string, int $offset = 0): false => false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read worker payload header.');

        WorkerPayloadSocket::readPayload($stream);
    }

    public function testReadPayloadThrowsWhenDecodedLengthIsInvalid(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);

        fwrite($stream, "\x00\x00\x00\x00");
        rewind($stream);

        $GLOBALS['mock_unpack_callback'] = static fn (string $format, string $string, int $offset = 0): array => [
            'length' => -1,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read worker payload header.');

        WorkerPayloadSocket::readPayload($stream);
    }

    public function testReadPayloadThrowsWhenPayloadIsNotAnArray(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);

        $payload = serialize('not-an-array');
        fwrite($stream, pack('N', strlen($payload)) . $payload);
        rewind($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid worker payload.');

        WorkerPayloadSocket::readPayload($stream);
    }

    public function testConnectCanOpenSocketConnection(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);

        $GLOBALS['mock_stream_socket_client_callback'] = static fn (): mixed => $stream;

        $this->assertSame($stream, WorkerPayloadSocket::connect('tcp://127.0.0.1:12345'));

        fclose($stream);
    }

    public function testConnectThrowsWhenSocketCannotBeOpened(): void
    {
        $GLOBALS['mock_stream_socket_client_callback'] = static function (
            string $address,
            ?int &$errorCode = null,
            ?string &$errorMessage = null
        ): false {
            $errorCode    = 111;
            $errorMessage = 'connection refused';

            return false;
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Unable to connect to parallel analysis worker socket [tcp://127.0.0.1:12345]: connection refused'
        );

        WorkerPayloadSocket::connect('tcp://127.0.0.1:12345', 0.1);
    }

    public function testCloseWriteLeavesReadOnlyStreamOpen(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'structarmed-socket-test');
        $this->assertIsString($path);
        file_put_contents($path, 'payload');

        $stream = fopen($path, 'r');
        $this->assertNotFalse($stream);

        try {
            WorkerPayloadSocket::closeWrite($stream);

            $this->assertTrue(is_resource($stream));
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function testCloseWriteClosesWritableStream(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);

        WorkerPayloadSocket::closeWrite($stream);

        $this->assertFalse(is_resource($stream));
    }

    public function testWriteAllThrowsWhenStreamCannotBeWritten(): void
    {
        $reflectionMethod = new ReflectionMethod(WorkerPayloadSocket::class, 'writeAll');

        $stream = fopen('php://temp', 'r');
        $this->assertNotFalse($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write worker payload.');

        $reflectionMethod->invoke(null, $stream, 'payload');
    }
}
