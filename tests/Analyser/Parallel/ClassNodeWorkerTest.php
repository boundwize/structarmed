<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\Parallel\ClassNodeWorker;
use Boundwize\StructArmed\Analyser\Parallel\WorkerFailedException;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_filter;
use function base64_decode;
use function end;
use function explode;
use function fclose;
use function file_put_contents;
use function fopen;
use function fwrite;
use function rewind;
use function serialize;
use function stream_get_contents;
use function unserialize;

#[CoversClass(ClassNodeWorker::class)]
#[CoversClass(WorkerFailedException::class)]
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

        $inputStream  = $this->makeInputStream([
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => [],
            'files'         => [$srcFile],
        ]);
        $outputStream = fopen('php://memory', 'w+');
        $this->assertNotFalse($outputStream);

        $exitCode = ClassNodeWorker::run($outputStream, $inputStream);

        $this->assertSame(0, $exitCode);

        $result = $this->readResult($outputStream);

        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['nodes']);

        fclose($inputStream);
        fclose($outputStream);
    }

    public function testRunWithInvalidPayloadReturnsOneAndWritesError(): void
    {
        $inputStream  = $this->makeInputStream('not-an-array');
        $outputStream = fopen('php://memory', 'w+');
        $this->assertNotFalse($outputStream);

        $exitCode = ClassNodeWorker::run($outputStream, $inputStream);

        $this->assertSame(1, $exitCode);

        $result = $this->readResult($outputStream);

        $this->assertSame([], $result['nodes']);
        $this->assertIsString($result['error']);

        fclose($inputStream);
        fclose($outputStream);
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

        $inputStream  = $this->makeInputStream([
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            'files'         => [$srcFile],
        ]);
        $outputStream = fopen('php://memory', 'w+');
        $this->assertNotFalse($outputStream);

        $exitCode = ClassNodeWorker::run($outputStream, $inputStream);

        $this->assertSame(0, $exitCode);

        $result = $this->readResult($outputStream);

        $this->assertNull($result['error']);
        $this->assertIsArray($result['nodes']);
        $this->assertCount(1, $result['nodes']);

        fclose($inputStream);
        fclose($outputStream);
    }

    /** @return resource */
    private function makeInputStream(mixed $payload): mixed
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, serialize($payload));
        rewind($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     * @return array<mixed>
     */
    private function readResult(mixed $stream): array
    {
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        $lines   = array_filter(explode("\n", $content));

        return (array) unserialize(base64_decode((string) end($lines)));
    }
}
