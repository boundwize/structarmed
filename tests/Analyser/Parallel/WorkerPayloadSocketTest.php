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
use function restore_error_handler;
use function rewind;
use function serialize;
use function set_error_handler;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(WorkerPayloadSocket::class)]
final class WorkerPayloadSocketTest extends TestCase
{
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
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);

        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);

        $client = WorkerPayloadSocket::connect('tcp://' . $address);

        fclose($client);
        fclose($server);
    }

    public function testConnectThrowsWhenSocketCannotBeOpened(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);

        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);
        fclose($server);

        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to connect to parallel analysis worker socket');

            WorkerPayloadSocket::connect('tcp://' . $address, 0.1);
        } finally {
            restore_error_handler();
        }
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
        $writeAll = new ReflectionMethod(WorkerPayloadSocket::class, 'writeAll');
        $writeAll->setAccessible(true);

        $stream = fopen('php://temp', 'r');
        $this->assertNotFalse($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write worker payload.');

        $writeAll->invoke(null, $stream, 'payload');
    }
}
