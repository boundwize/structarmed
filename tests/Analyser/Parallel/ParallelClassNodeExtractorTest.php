<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

// phpcs:disable
require_once __DIR__ . '/MockFunctions.php';
// phpcs:enable

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\Parallel\ParallelClassNodeExtractor;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_put_contents;

#[CoversClass(ParallelClassNodeExtractor::class)]
final class ParallelClassNodeExtractorTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testExtractWithEmptyFilesReturnsEmpty(): void
    {
        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: '/tmp',
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 4,
        );

        $result = $parallelClassNodeExtractor->extract([]);

        $this->assertSame([], $result);
    }

    public function testExtractWithWorkerCountOneUsesSequentialPath(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class Foo
{
}
PHP);

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 1,
        );

        $result = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ClassNode::class, $result[0]);
        $this->assertSame('App\\Domain\\Foo', $result[0]->className);
    }

    public function testExtractWithMultipleFilesUsesParallelPath(): void
    {
        $dir   = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file1 = $dir . '/Foo.php';
        $file2 = $dir . '/Bar.php';

        file_put_contents($file1, <<<'PHP'
<?php

namespace App\Domain;

final class Foo
{
}
PHP);

        file_put_contents($file2, <<<'PHP'
<?php

namespace App\Domain;

final class Bar
{
}
PHP);

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
        );

        $result = $parallelClassNodeExtractor->extract([$file1, $file2]);

        $this->assertCount(2, $result);
        $classNames = [$result[0]->className, $result[1]->className];
        $this->assertContains('App\\Domain\\Foo', $classNames);
        $this->assertContains('App\\Domain\\Bar', $classNames);
    }

    public function testExtractWithLayerPatternsUsesChainResolver(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Service.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class FooService
{
}
PHP);

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            workerCount: 2,
        );

        $result = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $result);
    }

    public function testExtractSequentialPathWithLayerPatterns(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/FooService.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class FooService
{
}
PHP);

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            workerCount: 1,
        );

        $result = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('App\\Domain\\FooService', $result[0]->className);
    }

    public function testExtractThrowsWhenWorkerFailsDueToNullByteInFilePath(): void
    {
        $dir = $this->makeTemporaryDirectory('structarmed-parallel-test');
        // A null byte in a file path causes PHP 8 to throw ValueError in file_get_contents,
        // which is NOT caught by ClassNodeExtractor's catch(PhpParser\Error), so it
        // propagates to ClassNodeWorker's catch(Throwable) → worker exits with code 1
        $fileWithNullByte = $dir . "/foo\x00.php";

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
        );

        $this->expectException(RuntimeException::class);
        $parallelClassNodeExtractor->extract([$fileWithNullByte]);
    }

    public function testExtractThrowsWhenProcOpenFails(): void
    {
        $GLOBALS['mock_proc_open'] = true;

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to start parallel analysis worker.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_proc_open'] = false;
        }
    }
}
