<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\Parallel\ClassNodeWorker;
use Boundwize\StructArmed\Analyser\Parallel\WorkerFailedException;
use Boundwize\StructArmed\Analyser\Parallel\WorkerPayloadSocket;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fclose;
use function file_put_contents;
use function fopen;
use function stream_get_meta_data;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;

#[CoversClass(ClassNodeWorker::class)]
#[CoversClass(WorkerFailedException::class)]
#[CoversClass(WorkerPayloadSocket::class)]
final class ClassNodeWorkerTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testRunWithValidPayloadReturnsZeroAndWritesNodes(): void
    {
        $dir     = $this->makeTemporaryDirectory('structarmed-worker-test');
        $srcFile = $dir . '/Foo.php';

        file_put_contents($srcFile, <<<'PHP'
<?php

namespace App\Domain;

final class Foo
{
}
PHP);

        ['client' => $clientStream, 'server' => $serverStream] = $this->createSocketStreams();

        WorkerPayloadSocket::writePayload($clientStream, [
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => [],
            'files'         => [$srcFile],
        ]);

        $exitCode = ClassNodeWorker::run('', '', $this->silentStream(), $serverStream);

        $this->assertSame(0, $exitCode);

        $result = WorkerPayloadSocket::readPayload($clientStream);

        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['nodes']);
    }

    public function testRunWithInvalidPayloadReturnsOneAndWritesError(): void
    {
        ['client' => $clientStream, 'server' => $serverStream] = $this->createSocketStreams();

        WorkerPayloadSocket::writePayload($clientStream, ['invalid-payload']);

        $exitCode = ClassNodeWorker::run('', '', $this->silentStream(), $serverStream);

        $this->assertSame(1, $exitCode);

        $result = WorkerPayloadSocket::readPayload($clientStream);

        $this->assertSame([], $result['nodes']);
        $this->assertIsString($result['error']);
    }

    public function testWorkerFailedExceptionExtendsRuntimeException(): void
    {
        $workerFailedException = new WorkerFailedException('test error');

        $this->assertInstanceOf(RuntimeException::class, $workerFailedException);
        $this->assertSame('test error', $workerFailedException->getMessage());
    }

    public function testRunWithLayerPatternsUsesClassNameRegexResolver(): void
    {
        $dir     = $this->makeTemporaryDirectory('structarmed-worker-test');
        $srcFile = $dir . '/FooService.php';

        file_put_contents($srcFile, <<<'PHP'
<?php

namespace App\Domain;

final class FooService
{
}
PHP);

        ['client' => $clientStream, 'server' => $serverStream] = $this->createSocketStreams();

        WorkerPayloadSocket::writePayload($clientStream, [
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            'files'         => [$srcFile],
        ]);

        $exitCode = ClassNodeWorker::run('', '', $this->silentStream(), $serverStream);

        $this->assertSame(0, $exitCode);

        $result = WorkerPayloadSocket::readPayload($clientStream);

        $this->assertNull($result['error']);
        $this->assertIsArray($result['nodes']);
        $this->assertCount(1, $result['nodes']);
    }

    /** @return resource */
    private function silentStream(): mixed
    {
        $stream = fopen('php://memory', 'w');
        $this->assertNotFalse($stream);

        return $stream;
    }

    /**
     * @return array{client: resource, server: resource}
     */
    private function createSocketStreams(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);

        stream_get_meta_data($server);

        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);

        $client   = WorkerPayloadSocket::connect('tcp://' . $address);
        $accepted = stream_socket_accept($server, 1);
        $this->assertNotFalse($accepted);

        fclose($server);

        return [
            'client' => $client,
            'server' => $accepted,
        ];
    }
}
