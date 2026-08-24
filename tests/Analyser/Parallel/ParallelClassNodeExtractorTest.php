<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\Parallel\ParallelClassNodeExtractor;
use Boundwize\StructArmed\Tests\Support\RecordingProgressHandler;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function bin2hex;
use function fclose;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_resource;
use function proc_close;
use function proc_open;
use function random_bytes;
use function range;
use function rmdir;
use function sort;
use function sprintf;
use function stream_get_contents;
use function stream_select;
use function sys_get_temp_dir;
use function unlink;

use const PHP_BINARY;

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

        $extractionResult = $parallelClassNodeExtractor->extract([]);

        $this->assertSame([], $extractionResult->classNodes);
        $this->assertSame([], $extractionResult->fileAnalyses);
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

        $extractionResult = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertInstanceOf(ClassNode::class, $extractionResult->classNodes[0]);
        $this->assertSame('App\\Domain\\Foo', $extractionResult->classNodes[0]->className);
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

        $extractionResult = $parallelClassNodeExtractor->extract([$file1, $file2]);

        $this->assertCount(2, $extractionResult->classNodes);
        $classNames = [$extractionResult->classNodes[0]->className, $extractionResult->classNodes[1]->className];
        $this->assertContains('App\\Domain\\Foo', $classNames);
        $this->assertContains('App\\Domain\\Bar', $classNames);
    }

    public function testExtractReturnsWorkerFacts(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';

        file_put_contents($file, '<?php final class Foo {} echo "side effect";');

        $extractionResult = (new ParallelClassNodeExtractor($dir, ['Source' => ''], [], 2))
            ->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertArrayHasKey($file, $extractionResult->fileAnalyses);
        $this->assertTrue($extractionResult->fileAnalyses[$file]->declaresSymbols);
        $this->assertTrue($extractionResult->fileAnalyses[$file]->hasSideEffects);
    }

    public function testExtractWithCacheDirectoryCreatesWorkerTempFilesInIt(): void
    {
        $dir      = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $cacheDir = $this->makeTemporaryDirectory('structarmed-parallel-cache');
        $file     = $dir . '/Baz.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class Baz
{
}
PHP);

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
            cacheDirectory: $cacheDir,
        );

        $extractionResult = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertSame('App\\Domain\\Baz', $extractionResult->classNodes[0]->className);
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

        $extractionResult = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
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

        $extractionResult = $parallelClassNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertSame('App\\Domain\\FooService', $extractionResult->classNodes[0]->className);
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

    public function testExtractWithNonExistentCacheDirectoryCreatesIt(): void
    {
        $dir      = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $cacheDir = sys_get_temp_dir() . '/structarmed-cache-mkdir-' . bin2hex(random_bytes(6));
        $file     = $dir . '/Qux.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class Qux
{
}
PHP);

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
            cacheDirectory: $cacheDir,
        );

        try {
            $result = $parallelClassNodeExtractor->extract([$file]);
            $this->assertCount(1, $result->classNodes);
        } finally {
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir . '/*') ?: [] as $tmpFile) {
                    @unlink($tmpFile);
                }

                rmdir($cacheDir);
            }
        }
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

    public function testExtractReportsStderrWhenWorkerDiesBeforeWritingPayload(): void
    {
        // Simulates a worker killed by OOM / fatal error before ClassNodeWorker can serialize a result:
        // non-zero exit code, empty output file, diagnostic on stderr.
        $GLOBALS['mock_proc_open_command'] = [
            PHP_BINARY,
            '-r',
            'fwrite(STDERR, "simulated worker fatal"); exit(255);',
        ];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        try {
            $parallelClassNodeExtractor->extract([$file]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('Parallel analysis worker failed:', $runtimeException->getMessage());
            $this->assertStringContainsString('worker exited with code 255', $runtimeException->getMessage());
            $this->assertStringContainsString('simulated worker fatal', $runtimeException->getMessage());
            $this->assertStringNotContainsString('invalid payload', $runtimeException->getMessage());
        } finally {
            $GLOBALS['mock_proc_open_command'] = null;
        }
    }

    public function testExtractThrowsWhenTempnamFails(): void
    {
        $GLOBALS['mock_tempnam'] = true;

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create temporary file for parallel analysis.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_tempnam'] = false;
        }
    }

    public function testExtractThrowsWhenPayloadIsInvalid(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = ['invalid' => 'payload'];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned an invalid payload.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    public function testExtractThrowsWhenExitZeroWorkerReportsErrorInPayload(): void
    {
        // Worker exits 0 (real worker run) but the payload carries an error string; the error must be reported
        // even without a non-zero exit code.
        $GLOBALS['mock_file_get_contents_payload'] = ['nodes' => [], 'error' => 'simulated payload error'];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker failed: simulated payload error');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    public function testExtractThrowsWhenErrorPayloadIsInvalid(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = ['nodes' => [], 'error' => ['not_a_string']];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned an invalid error payload.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    public function testExtractThrowsWhenFileAnalysesPayloadIsNotAnArray(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = [
            'nodes'        => [],
            'fileAnalyses' => 'invalid',
            'error'        => null,
        ];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned invalid file analyses.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    public function testExtractThrowsWhenFileAnalysisEntryIsInvalid(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = [
            'nodes'        => [],
            'fileAnalyses' => ['Foo.php' => 'invalid'],
            'error'        => null,
        ];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned invalid file analyses.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    public function testExtractThrowsWhenAnonymousClassNodesPayloadIsNotAnArray(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = [
            'nodes'               => [],
            'fileAnalyses'        => [],
            'anonymousClassNodes' => 'invalid',
            'error'               => null,
        ];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned invalid anonymous class nodes.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    public function testExtractThrowsWhenAnonymousClassNodeEntryIsInvalid(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = [
            'nodes'               => [],
            'fileAnalyses'        => [],
            'anonymousClassNodes' => ['invalid'],
            'error'               => null,
        ];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned invalid anonymous class nodes.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    public function testExtractThrowsWhenFileReferencesPayloadIsNotAnArray(): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = [
            'nodes'               => [],
            'fileAnalyses'        => [],
            'anonymousClassNodes' => [],
            'fileReferences'      => 'invalid',
            'error'               => null,
        ];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned invalid file references.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    /**
     * @return Iterator<string, array{mixed}>
     */
    public static function invalidFileReferencesEntryProvider(): Iterator
    {
        yield 'entry not an array' => [['Foo.php' => 'invalid']];
        yield 'entry with non-string reference' => [['Foo.php' => [1]]];
    }

    /**
     * @return Iterator<string, array{mixed}>
     */
    public static function invalidFileInstantiationsProvider(): Iterator
    {
        yield 'not an array' => ['invalid'];
        yield 'entry not an array' => [['Foo.php' => 'invalid']];
        yield 'entry with non-string instantiation' => [['Foo.php' => [1]]];
    }

    #[DataProvider('invalidFileInstantiationsProvider')]
    public function testExtractThrowsWhenFileInstantiationsPayloadIsInvalid(mixed $invalidFileInstantiations): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = [
            'nodes'               => [],
            'fileAnalyses'        => [],
            'anonymousClassNodes' => [],
            'fileReferences'      => [],
            'fileInstantiations'  => $invalidFileInstantiations,
            'error'               => null,
        ];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned invalid file instantiations.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    #[DataProvider('invalidFileReferencesEntryProvider')]
    public function testExtractThrowsWhenFileReferencesEntryIsInvalid(mixed $invalidFileReferences): void
    {
        $GLOBALS['mock_file_get_contents_payload'] = [
            'nodes'               => [],
            'fileAnalyses'        => [],
            'anonymousClassNodes' => [],
            'fileReferences'      => $invalidFileReferences,
            'error'               => null,
        ];

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned invalid file references.');

        try {
            $parallelClassNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }

    /**
     * Stress the socket + stream_select() wait loop: many files spread across several workers must yield
     * exactly one progress event per file, with none lost or duplicated. This runs on every CI OS, which
     * matters most on Windows where proc_open() pipes are not selectable and a socket pair is used instead.
     */
    public function testProgressEventsAreReceivedForEveryFileAcrossManyWorkers(): void
    {
        $dir   = $this->makeTemporaryDirectory('structarmed-parallel-stress');
        $files = array_map(static function (int $i) use ($dir): string {
            $file = sprintf('%s/Class%03d.php', $dir, $i);

            file_put_contents($file, sprintf(<<<'PHP'
<?php

namespace App\Domain;

final class Class%03d
{
}
PHP, $i));

            return $file;
        }, range(1, 120));

        $recordingProgressHandler = new RecordingProgressHandler();

        $parallelClassNodeExtractor = new ParallelClassNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 6,
        );

        $extractionResult = $parallelClassNodeExtractor->extract($files, $recordingProgressHandler);

        $this->assertCount(120, $extractionResult->classNodes);

        $expected = $files;
        $advanced = $recordingProgressHandler->advanced();
        sort($expected);
        sort($advanced);

        $this->assertSame($expected, $advanced);
    }

    /**
     * The extractor relies on stream_select() reporting a proc_open() ['socket'] stream as readable once the
     * child writes. If that silently failed (returned false) the wait loop would still terminate, because it
     * polls again, but it would have degraded into a busy loop. Assert the primitive itself on every CI OS.
     */
    public function testStreamSelectReportsProcOpenSocketAsReadable(): void
    {
        $pipes   = [];
        $process = proc_open(
            [PHP_BINARY, '-r', 'usleep(100000); echo "ready";'],
            [
                0 => ['pipe', 'r'],
                1 => ['socket'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        $this->assertTrue(is_resource($process));
        $this->assertArrayHasKey(1, $pipes);

        $read   = [$pipes[1]];
        $write  = null;
        $except = null;

        $selected = stream_select($read, $write, $except, 5);

        $this->assertSame(1, $selected);
        $this->assertSame([$pipes[1]], $read);
        $this->assertSame('ready', stream_get_contents($pipes[1]));

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process));
    }

    /**
     * Progress must be complete even if the stdout socket delivers no markers at all: a worker that exited
     * cleanly has processed its whole bucket, so the extractor reports the remainder itself. Windows CI
     * observed EOF before the final bytes were read, losing the tail of each worker's progress.
     */
    public function testProgressIsCompletedOnCleanExitWhenNoMarkersWereReceived(): void
    {
        // A "worker" that writes nothing to stdout and exits 0; the result payload is supplied via the mock.
        $GLOBALS['mock_proc_open_command']         = [PHP_BINARY, '-r', 'exit(0);'];
        $GLOBALS['mock_file_get_contents_payload'] = ['nodes' => [], 'error' => null];

        $dir   = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $files = [];

        foreach (['Foo', 'Bar', 'Baz'] as $name) {
            $files[] = $file = sprintf('%s/%s.php', $dir, $name);
            file_put_contents($file, sprintf('<?php class %s {}', $name));
        }

        $recordingProgressHandler   = new RecordingProgressHandler();
        $parallelClassNodeExtractor = new ParallelClassNodeExtractor($dir, [], [], 1);

        try {
            $parallelClassNodeExtractor->extract($files, $recordingProgressHandler);
        } finally {
            $GLOBALS['mock_proc_open_command']         = null;
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }

        $this->assertSame($files, $recordingProgressHandler->advanced());
    }
}
