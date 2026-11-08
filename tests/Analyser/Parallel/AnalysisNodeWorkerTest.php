<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\Parallel\AnalysisNodeWorker;
use Boundwize\StructArmed\Analyser\Parallel\WorkerFailedException;
use Boundwize\StructArmed\Analyser\Parallel\WorkerProgressHandler;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_get_contents;
use function file_put_contents;
use function fopen;
use function unserialize;

#[CoversClass(AnalysisNodeWorker::class)]
#[CoversClass(WorkerProgressHandler::class)]
#[CoversClass(WorkerFailedException::class)]
final class AnalysisNodeWorkerTest extends TestCase
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

        $outputFile = $this->makeTemporaryFile('structarmed-worker-output');

        $exitCode = AnalysisNodeWorker::run([
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => [],
            'files'         => [$srcFile],
        ], $outputFile, $this->silentStream());

        $this->assertSame(0, $exitCode);

        $result = unserialize((string) file_get_contents($outputFile));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['nodes']);
    }

    public function testRunStoresAnalysisNodesInSuppliedCache(): void
    {
        $dir      = $this->makeTemporaryDirectory('structarmed-worker-test');
        $cacheDir = $this->makeTemporaryDirectory('structarmed-worker-cache');
        $srcFile  = $dir . '/Foo.php';

        file_put_contents($srcFile, <<<'PHP'
<?php

namespace App\Domain;

final class Foo
{
}
PHP);

        $outputFile = $this->makeTemporaryFile('structarmed-worker-output');

        $this->assertSame(0, AnalysisNodeWorker::run([
            'basePath'       => $dir,
            'layers'         => ['Domain' => 'App\\Domain'],
            'layerPatterns'  => [],
            'files'          => [$srcFile],
            'cache'          => new AnalysisResultCache($dir, new FileHashProvider(), $cacheDir),
            'cacheNamespace' => 'namespace',
        ], $outputFile, $this->silentStream()));

        $cached = (new AnalysisResultCache($dir, new FileHashProvider(), $cacheDir))
            ->loadAnalysisNodes($srcFile, 'namespace', true);

        $this->assertNotNull($cached);
        $this->assertCount(1, $cached['classNodes']);
        $this->assertSame('App\\Domain\\Foo', $cached['classNodes'][0]->className);
    }

    public function testRunCollectsSignaturesOnlyWhenThePayloadAsksForThem(): void
    {
        $dir     = $this->makeTemporaryDirectory('structarmed-worker-test');
        $srcFile = $dir . '/Foo.php';

        file_put_contents($srcFile, <<<'PHP'
<?php

final class Foo
{
    public function run(Bar $bar): void
    {
    }
}
PHP);

        $outputFile = $this->makeTemporaryFile('structarmed-worker-output');
        $payload    = ['basePath' => $dir, 'layers' => [], 'layerPatterns' => [], 'files' => [$srcFile]];

        foreach ([[[], 0], [['withSignatures' => true], 1]] as [$option, $signatureCount]) {
            $this->assertSame(0, AnalysisNodeWorker::run($payload + $option, $outputFile, $this->silentStream()));

            $result = unserialize((string) file_get_contents($outputFile));

            $this->assertIsArray($result);
            $this->assertIsArray($result['nodes']);
            $this->assertInstanceOf(ClassNode::class, $result['nodes'][0]);
            $this->assertCount($signatureCount, $result['nodes'][0]->signatures);
        }
    }

    public function testRunWithInvalidPayloadReturnsOneAndWritesError(): void
    {
        $outputFile = $this->makeTemporaryFile('structarmed-worker-output');

        $exitCode = AnalysisNodeWorker::run('not-an-array', $outputFile, $this->silentStream());

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

        $outputFile = $this->makeTemporaryFile('structarmed-worker-output');

        $exitCode = AnalysisNodeWorker::run([
            'basePath'      => $dir,
            'layers'        => ['Domain' => 'App\\Domain'],
            'layerPatterns' => ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            'files'         => [$srcFile],
        ], $outputFile, $this->silentStream());

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
