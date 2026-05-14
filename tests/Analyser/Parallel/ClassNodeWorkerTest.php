<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\Parallel\ClassNodeWorker;
use Boundwize\StructArmed\Analyser\Parallel\WorkerFailedException;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_get_contents;
use function file_put_contents;
use function fopen;
use function serialize;
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

        $inputFile  = $this->makeTemporaryFile('structarmed-worker-input');
        $outputFile = $this->makeTemporaryFile('structarmed-worker-output');

        file_put_contents($inputFile, serialize([
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => [],
            'files'         => [$srcFile],
        ]));

        $exitCode = ClassNodeWorker::run($inputFile, $outputFile, $this->silentStream());

        $this->assertSame(0, $exitCode);

        $result = unserialize((string) file_get_contents($outputFile));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['nodes']);
    }

    public function testRunWithInvalidPayloadReturnsOneAndWritesError(): void
    {
        $inputFile  = $this->makeTemporaryFile('structarmed-worker-input');
        $outputFile = $this->makeTemporaryFile('structarmed-worker-output');

        file_put_contents($inputFile, serialize('not-an-array'));

        $exitCode = ClassNodeWorker::run($inputFile, $outputFile, $this->silentStream());

        $this->assertSame(1, $exitCode);

        $result = unserialize((string) file_get_contents($outputFile));

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

        $inputFile  = $this->makeTemporaryFile('structarmed-worker-input');
        $outputFile = $this->makeTemporaryFile('structarmed-worker-output');

        file_put_contents($inputFile, serialize([
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            'files'         => [$srcFile],
        ]));

        $exitCode = ClassNodeWorker::run($inputFile, $outputFile, $this->silentStream());

        $this->assertSame(0, $exitCode);

        $result = unserialize((string) file_get_contents($outputFile));

        $this->assertIsArray($result);
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
}
