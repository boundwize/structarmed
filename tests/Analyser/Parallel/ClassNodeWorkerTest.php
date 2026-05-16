<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\Parallel\ClassNodeWorker;
use Boundwize\StructArmed\Analyser\Parallel\WorkerFailedException;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

        $input  = $this->makeInputStream(serialize([
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => [],
            'files'         => [$srcFile],
        ]));
        $output = $this->makeOutputStream();

        $exitCode = ClassNodeWorker::run($input, $output, $this->silentStream());

        $this->assertSame(0, $exitCode);

        rewind($output);
        $result = unserialize((string) stream_get_contents($output));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['nodes']);
    }

    public function testRunWithInvalidPayloadReturnsOneAndWritesError(): void
    {
        $input  = $this->makeInputStream(serialize('not-an-array'));
        $output = $this->makeOutputStream();

        $exitCode = ClassNodeWorker::run($input, $output, $this->silentStream());

        $this->assertSame(1, $exitCode);

        rewind($output);
        $result = unserialize((string) stream_get_contents($output));

        $this->assertIsArray($result);
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

        $input  = $this->makeInputStream(serialize([
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            'files'         => [$srcFile],
        ]));
        $output = $this->makeOutputStream();

        $exitCode = ClassNodeWorker::run($input, $output, $this->silentStream());

        $this->assertSame(0, $exitCode);

        rewind($output);
        $result = unserialize((string) stream_get_contents($output));

        $this->assertIsArray($result);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['nodes']);
        $this->assertCount(1, $result['nodes']);
    }

    /** @return resource */
    private function makeInputStream(string $payload): mixed
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, $payload);
        rewind($stream);

        return $stream;
    }

    /** @return resource */
    private function makeOutputStream(): mixed
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertNotFalse($stream);

        return $stream;
    }

    /** @return resource */
    private function silentStream(): mixed
    {
        $stream = fopen('php://memory', 'w');
        $this->assertNotFalse($stream);

        return $stream;
    }
}
