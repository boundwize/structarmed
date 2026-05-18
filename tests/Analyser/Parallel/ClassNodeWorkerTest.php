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

use function file_put_contents;
use function fopen;
use function rewind;

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

        $payloadStream = $this->memoryStream();
        WorkerPayloadSocket::writePayload($payloadStream, [
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => [],
            'files'         => [$srcFile],
        ]);
        rewind($payloadStream);

        $resultStream = $this->memoryStream();

        $exitCode = ClassNodeWorker::run($this->silentStream(), $payloadStream, $resultStream);

        $this->assertSame(0, $exitCode);

        rewind($resultStream);
        $result = WorkerPayloadSocket::readPayload($resultStream);

        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['nodes']);
    }

    public function testRunWithInvalidPayloadReturnsOneAndWritesError(): void
    {
        $payloadStream = $this->memoryStream();
        WorkerPayloadSocket::writePayload($payloadStream, ['invalid-payload']);
        rewind($payloadStream);

        $resultStream = $this->memoryStream();

        $exitCode = ClassNodeWorker::run($this->silentStream(), $payloadStream, $resultStream);

        $this->assertSame(1, $exitCode);

        rewind($resultStream);
        $result = WorkerPayloadSocket::readPayload($resultStream);

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

        $payloadStream = $this->memoryStream();
        WorkerPayloadSocket::writePayload($payloadStream, [
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            'files'         => [$srcFile],
        ]);
        rewind($payloadStream);

        $resultStream = $this->memoryStream();

        $exitCode = ClassNodeWorker::run($this->silentStream(), $payloadStream, $resultStream);

        $this->assertSame(0, $exitCode);

        rewind($resultStream);
        $result = WorkerPayloadSocket::readPayload($resultStream);

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

    /** @return resource */
    private function memoryStream(): mixed
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);

        return $stream;
    }
}
