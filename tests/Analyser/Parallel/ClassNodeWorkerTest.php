<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\Parallel\ClassNodeWorker;
use Boundwize\StructArmed\Analyser\Parallel\WorkerFailedException;
use Boundwize\StructArmed\Analyser\Parallel\WorkerPayloadSocket;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function file_put_contents;
use function fopen;
use function is_resource;
use function rewind;
use function stream_get_contents;
use function substr_count;

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

    public function testRunWithInvalidPayloadStreamReturnsOneAndWritesError(): void
    {
        $resultStream     = $this->memoryStream();
        $reflectionMethod = new ReflectionMethod(ClassNodeWorker::class, 'run');

        $exitCode = $reflectionMethod->invoke(null, $this->silentStream(), 'not-a-stream', $resultStream);

        $this->assertSame(1, $exitCode);

        rewind($resultStream);
        $result = WorkerPayloadSocket::readPayload($resultStream);

        $this->assertSame([], $result['nodes']);
        $this->assertIsString($result['error']);
        $this->assertStringContainsString('Invalid worker payload stream.', $result['error']);
    }

    public function testRunWithInvalidProgressStreamReturnsOneAndWritesError(): void
    {
        $payloadStream = $this->memoryStream();
        WorkerPayloadSocket::writePayload($payloadStream, [
            'basePath'      => __DIR__,
            'layers'        => [],
            'layerPatterns' => [],
            'files'         => [],
        ]);
        rewind($payloadStream);

        $resultStream     = $this->memoryStream();
        $reflectionMethod = new ReflectionMethod(ClassNodeWorker::class, 'run');

        $exitCode = $reflectionMethod->invoke(null, 'not-a-stream', $payloadStream, $resultStream);

        $this->assertSame(1, $exitCode);

        rewind($resultStream);
        $result = WorkerPayloadSocket::readPayload($resultStream);

        $this->assertSame([], $result['nodes']);
        $this->assertIsString($result['error']);
        $this->assertStringContainsString('Invalid worker progress stream.', $result['error']);
    }

    public function testRunWithInvalidResultStreamReturnsOne(): void
    {
        $payloadStream = $this->memoryStream();
        WorkerPayloadSocket::writePayload($payloadStream, [
            'basePath'      => __DIR__,
            'layers'        => [],
            'layerPatterns' => [],
            'files'         => [],
        ]);
        rewind($payloadStream);

        $reflectionMethod = new ReflectionMethod(ClassNodeWorker::class, 'run');

        $exitCode = $reflectionMethod->invoke(null, $this->silentStream(), $payloadStream, 'not-a-stream');

        $this->assertSame(1, $exitCode);
    }

    public function testRunClosesImplicitResultStreamOnFailure(): void
    {
        $resultStream     = $this->memoryStream();
        $reflectionMethod = new ReflectionMethod(ClassNodeWorker::class, 'runWithStreams');

        $exitCode = $reflectionMethod->invoke(null, $this->silentStream(), 'not-a-stream', $resultStream, true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(is_resource($resultStream));
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

    public function testRunBatchesProgressWritesAcrossMultipleFiles(): void
    {
        $dir   = $this->makeTemporaryDirectory('structarmed-worker-test');
        $files = [];

        foreach (['Foo', 'Bar', 'Baz'] as $className) {
            $file = $dir . '/' . $className . '.php';
            file_put_contents($file, <<<PHP
<?php

namespace App\\Domain;

final class {$className}
{
}
PHP);
            $files[] = $file;
        }

        $payloadStream = $this->memoryStream();
        WorkerPayloadSocket::writePayload($payloadStream, [
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => [],
            'files'         => $files,
        ]);
        rewind($payloadStream);

        $progressStream = $this->memoryStream();
        $resultStream   = $this->memoryStream();

        $exitCode = ClassNodeWorker::run($progressStream, $payloadStream, $resultStream);

        $this->assertSame(0, $exitCode);

        rewind($progressStream);
        $progressOutput = stream_get_contents($progressStream);
        $this->assertIsString($progressOutput);
        $this->assertSame(3, substr_count($progressOutput, "\n"));
    }

    public function testRunSkipsProgressWritesWhenProgressTrackingIsDisabled(): void
    {
        $dir   = $this->makeTemporaryDirectory('structarmed-worker-test');
        $files = [];

        foreach (['Foo', 'Bar', 'Baz'] as $className) {
            $file = $dir . '/' . $className . '.php';
            file_put_contents($file, <<<PHP
<?php

namespace App\\Domain;

final class {$className}
{
}
PHP);
            $files[] = $file;
        }

        $payloadStream = $this->memoryStream();
        WorkerPayloadSocket::writePayload($payloadStream, [
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => [],
            'files'         => $files,
            'trackProgress' => false,
        ]);
        rewind($payloadStream);

        $progressStream = $this->memoryStream();
        $resultStream   = $this->memoryStream();

        $exitCode = ClassNodeWorker::run($progressStream, $payloadStream, $resultStream);

        $this->assertSame(0, $exitCode);

        rewind($progressStream);
        $progressOutput = stream_get_contents($progressStream);
        $this->assertIsString($progressOutput);
        $this->assertSame('', $progressOutput);
    }

    public function testRunBatchClosesImplicitResultStreamOnSuccess(): void
    {
        $reflectionMethod = new ReflectionMethod(ClassNodeWorker::class, 'runBatch');

        $progressStream = $this->silentStream();
        $resultStream   = $this->memoryStream();

        $exitCode = $reflectionMethod->invoke(null, [
            'basePath'      => __DIR__,
            'layers'        => [],
            'layerPatterns' => [],
            'files'         => [],
        ], $progressStream, $resultStream, true);

        $this->assertSame(0, $exitCode);
        $this->assertFalse(is_resource($resultStream));
    }

    public function testRunBatchClosesImplicitResultStreamOnFailure(): void
    {
        $reflectionMethod = new ReflectionMethod(ClassNodeWorker::class, 'runBatch');

        $resultStream = $this->memoryStream();

        $exitCode = $reflectionMethod->invoke(null, ['invalid-payload'], $this->silentStream(), $resultStream, true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(is_resource($resultStream));
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
