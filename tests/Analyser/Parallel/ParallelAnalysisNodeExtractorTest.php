<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser\Parallel;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\Parallel\ParallelAnalysisNodeExtractor;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Cache\FileHashProvider;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function bin2hex;
use function file_put_contents;
use function glob;
use function ini_get;
use function ini_set;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sort;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

#[CoversClass(ParallelAnalysisNodeExtractor::class)]
final class ParallelAnalysisNodeExtractorTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testExtractWithEmptyFilesReturnsEmpty(): void
    {
        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor(
            basePath: '/tmp',
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 4,
        );

        $extractionResult = $parallelAnalysisNodeExtractor->extract([]);

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

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 1,
        );

        $extractionResult = $parallelAnalysisNodeExtractor->extract([$file]);

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

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
        );

        $extractionResult = $parallelAnalysisNodeExtractor->extract([$file1, $file2]);

        $this->assertCount(2, $extractionResult->classNodes);
        $classNames = [$extractionResult->classNodes[0]->className, $extractionResult->classNodes[1]->className];
        $this->assertContains('App\\Domain\\Foo', $classNames);
        $this->assertContains('App\\Domain\\Bar', $classNames);
        // stream_select() needs a socket to wait on worker stdout on Windows
        $this->assertSame(
            PHP_OS_FAMILY === 'Windows' ? ['socket'] : ['pipe', 'w'],
            $GLOBALS['mock_proc_open_seen_stdout']
        );
    }

    public function testExtractReturnsWorkerFacts(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';

        file_put_contents($file, '<?php final class Foo {} echo "side effect";');

        $extractionResult = (new ParallelAnalysisNodeExtractor($dir, ['Source' => ''], [], 2))
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

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
            cacheDirectory: $cacheDir,
        );

        $extractionResult = $parallelAnalysisNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertSame('App\\Domain\\Baz', $extractionResult->classNodes[0]->className);
    }

    public function testWorkersStoreValidAnalysisNodeCacheEntriesWithScopedFileHashes(): void
    {
        $dir      = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $cacheDir = $this->makeTemporaryDirectory('structarmed-parallel-cache');
        $fooFile  = $dir . '/Foo.php';
        $barFile  = $dir . '/Bar.php';

        file_put_contents($fooFile, '<?php namespace App; final class Foo {}');
        file_put_contents($barFile, '<?php namespace App; final class Bar {}');

        $fileHashProvider = new FileHashProvider();
        $fileHashProvider->hash($fooFile);
        $fileHashProvider->hash($barFile);

        $analysisResultCache = new AnalysisResultCache($dir, $fileHashProvider, $cacheDir);

        (new ParallelAnalysisNodeExtractor(
            basePath: $dir,
            layers: [],
            layerPatterns: [],
            workerCount: 2,
            cacheDirectory: $cacheDir,
            analysisResultCache: $analysisResultCache,
            analysisNodeCacheNamespace: 'config',
        ))->extract([$fooFile, $barFile]);

        $freshCache = new AnalysisResultCache($dir, new FileHashProvider(), $cacheDir);

        $this->assertIsArray($freshCache->loadAnalysisNodes($fooFile, 'config'));
        $this->assertIsArray($freshCache->loadAnalysisNodes($barFile, 'config'));

        file_put_contents($fooFile, '<?php namespace App; final class Foo { public function changed(): void {} }');

        $changedFileCache = new AnalysisResultCache($dir, new FileHashProvider(), $cacheDir);

        $this->assertNull($changedFileCache->loadAnalysisNodes($fooFile, 'config'));
        $this->assertIsArray($changedFileCache->loadAnalysisNodes($barFile, 'config'));
    }

    public function testWorkersHydrateCachedFilesAndReportOnlyParsedFilesAsProgress(): void
    {
        $dir      = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $cacheDir = $this->makeTemporaryDirectory('structarmed-parallel-cache');
        $fooFile  = $dir . '/Foo.php';
        $barFile  = $dir . '/Bar.php';

        file_put_contents($fooFile, '<?php namespace App; final class Foo {}');
        file_put_contents($barFile, '<?php namespace App; final class Bar {}');

        $extractor = static fn (): ParallelAnalysisNodeExtractor => new ParallelAnalysisNodeExtractor(
            basePath: $dir,
            layers: [],
            layerPatterns: [],
            workerCount: 2,
            cacheDirectory: $cacheDir,
            analysisResultCache: new AnalysisResultCache($dir, new FileHashProvider(), $cacheDir),
            analysisNodeCacheNamespace: 'config',
        );

        $extractor()->extract([$fooFile, $barFile]);

        file_put_contents($fooFile, '<?php namespace App; final class Foo { public function changed(): void {} }');

        $progress = new class implements ProgressHandlerInterface {
            public int $total = -1;

            /** @var list<string> */
            public array $files = [];

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
            }
        };

        $extractionResult = $extractor()->extract([$fooFile, $barFile], $progress);
        $classNames       = array_map(
            static fn (ClassNode $classNode): string => $classNode->className,
            $extractionResult->classNodes
        );
        sort($classNames);

        $this->assertCount(2, $classNames);
        $this->assertStringEndsWith('\\Bar', $classNames[0]);
        $this->assertStringEndsWith('\\Foo', $classNames[1]);
        $this->assertSame(1, $progress->total);
        $this->assertSame([$fooFile], $progress->files);

        $progress->total = -1;
        $progress->files = [];

        $extractor()->extract([$fooFile, $barFile], $progress);

        $this->assertSame(0, $progress->total);
        $this->assertSame([], $progress->files);
    }

    /**
     * @return iterable<string, array{list<string>|null}>
     */
    public static function provideStdoutDescriptors(): iterable
    {
        yield 'platform default' => [null];
        yield 'socket, as used on Windows' => [['socket']];
    }

    /**
     * @param list<string>|null $stdoutDescriptor
     */
    #[DataProvider('provideStdoutDescriptors')]
    public function testExtractAcrossWorkersWithAndWithoutProgress(?array $stdoutDescriptor): void
    {
        $GLOBALS['mock_proc_open_stdout'] = $stdoutDescriptor;

        $dir   = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $files = [];

        for ($index = 0; $index < 6; ++$index) {
            $file = sprintf('%s/Foo%d.php', $dir, $index);
            file_put_contents($file, sprintf('<?php namespace App; final class Foo%d {}', $index));

            $files[] = $file;
        }

        $progress = new class implements ProgressHandlerInterface {
            public int $total = -1;

            /** @var list<string> */
            public array $files = [];

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
            }
        };

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor($dir, [], [], 3);

        try {
            $withoutProgress = $parallelAnalysisNodeExtractor->extract($files);
            $withProgress    = $parallelAnalysisNodeExtractor->extract($files, $progress);
        } finally {
            $GLOBALS['mock_proc_open_stdout'] = null;
        }

        sort($progress->files);

        $this->assertCount(6, $withoutProgress->classNodes);
        $this->assertCount(6, $withProgress->classNodes);
        $this->assertSame(6, $progress->total);
        $this->assertSame($files, $progress->files);
    }

    /**
     * @param list<string>|null $stdoutDescriptor
     */
    #[DataProvider('provideStdoutDescriptors')]
    public function testExtractBuffersProgressLineSplitAcrossReads(?array $stdoutDescriptor): void
    {
        // Simulates a worker whose progress lines reach the coordinator in pieces: index 10 arrives as "1" then
        // "0\n", index 3 as "3" then "\n".
        $GLOBALS['mock_proc_open_stdout']          = $stdoutDescriptor;
        $GLOBALS['mock_proc_open_command']         = [
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, "2\n1"); usleep(50000); fwrite(STDOUT, "0\n3"); usleep(50000); fwrite(STDOUT, "\n");',
        ];
        $GLOBALS['mock_file_get_contents_payload'] = ['nodes' => [], 'error' => null];

        $dir   = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $files = [];

        // same size files keep their order in the single worker's chunk
        for ($index = 0; $index < 11; ++$index) {
            $file = sprintf('%s/Foo%02d.php', $dir, $index);
            file_put_contents($file, '<?php');

            $files[] = $file;
        }

        $progress = new class implements ProgressHandlerInterface {
            public int $total = -1;

            /** @var list<string> */
            public array $files = [];

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
            }
        };

        try {
            (new ParallelAnalysisNodeExtractor($dir, [], [], 1))->extract($files, $progress);
        } finally {
            $GLOBALS['mock_proc_open_stdout']          = null;
            $GLOBALS['mock_proc_open_command']         = null;
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }

        $this->assertSame(2, $progress->total);
        $this->assertSame([$files[10], $files[3]], $progress->files);
    }

    public function testExtractWaitsForFirstProgressLineBeyondDefaultSocketTimeout(): void
    {
        // A socket stream stops a blocking read after default_socket_timeout, a pipe never does: the progress total
        // of a worker that is slow to report it must not be lost, nor later be mistaken for a file index.
        $defaultSocketTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '1');

        $GLOBALS['mock_proc_open_stdout']          = ['socket'];
        $GLOBALS['mock_proc_open_command']         = [
            PHP_BINARY,
            '-r',
            'usleep(1200000); fwrite(STDOUT, "1\n0\n");',
        ];
        $GLOBALS['mock_file_get_contents_payload'] = ['nodes' => [], 'error' => null];

        $dir   = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file1 = $dir . '/Foo.php';
        $file2 = $dir . '/Bar.php';
        file_put_contents($file1, '<?php');
        file_put_contents($file2, '<?php');

        $progress = new class implements ProgressHandlerInterface {
            public int $total = -1;

            /** @var list<string> */
            public array $files = [];

            public function start(int $total): void
            {
                $this->total = $total;
            }

            public function advance(string $file): void
            {
                $this->files[] = $file;
            }

            public function finish(): void
            {
            }
        };

        try {
            (new ParallelAnalysisNodeExtractor($dir, [], [], 1))->extract([$file1, $file2], $progress);
        } finally {
            ini_set('default_socket_timeout', $defaultSocketTimeout);

            $GLOBALS['mock_proc_open_stdout']          = null;
            $GLOBALS['mock_proc_open_command']         = null;
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }

        $this->assertSame(1, $progress->total);
        $this->assertSame([$file1], $progress->files);
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

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            workerCount: 2,
        );

        $extractionResult = $parallelAnalysisNodeExtractor->extract([$file]);

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

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: ['Domain' => ['pattern' => '/Service$/', 'excludePattern' => null]],
            workerCount: 1,
        );

        $extractionResult = $parallelAnalysisNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertSame('App\\Domain\\FooService', $extractionResult->classNodes[0]->className);
    }

    public function testExtractThrowsWhenWorkerFailsDueToNullByteInFilePath(): void
    {
        $dir = $this->makeTemporaryDirectory('structarmed-parallel-test');
        // A null byte in a file path causes PHP 8 to throw ValueError in file_get_contents,
        // which is NOT caught by AnalysisNodeExtractor's catch(PhpParser\Error), so it
        // propagates to AnalysisNodeWorker's catch(Throwable) → worker exits with code 1
        $fileWithNullByte = $dir . "/foo\x00.php";

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
        );

        $this->expectException(RuntimeException::class);
        $parallelAnalysisNodeExtractor->extract([$fileWithNullByte]);
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

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor(
            basePath: $dir,
            layers: ['Domain' => 'App\\Domain'],
            layerPatterns: [],
            workerCount: 2,
            cacheDirectory: $cacheDir,
        );

        try {
            $result = $parallelAnalysisNodeExtractor->extract([$file]);
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

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to start parallel analysis worker.');

        try {
            $parallelAnalysisNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_proc_open'] = false;
        }
    }

    /**
     * @param list<string>|null $stdoutDescriptor
     */
    #[DataProvider('provideStdoutDescriptors')]
    public function testExtractReportsStderrWhenWorkerDiesBeforeWritingPayload(?array $stdoutDescriptor): void
    {
        // Simulates a worker killed by OOM / fatal error before AnalysisNodeWorker can serialize a result:
        // non-zero exit code, empty output file, diagnostic on stderr. Both workers die; each failure is reported.
        $GLOBALS['mock_proc_open_stdout']  = $stdoutDescriptor;
        $GLOBALS['mock_proc_open_command'] = [
            PHP_BINARY,
            '-r',
            'fwrite(STDERR, "simulated worker fatal"); exit(255);',
        ];

        $dir   = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file1 = $dir . '/Foo.php';
        $file2 = $dir . '/Bar.php';
        file_put_contents($file1, '<?php class Foo {}');
        file_put_contents($file2, '<?php class Bar {}');

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor($dir, [], [], 2);

        try {
            $parallelAnalysisNodeExtractor->extract([$file1, $file2]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString(
                '[worker #1] Parallel analysis worker failed:',
                $runtimeException->getMessage()
            );
            $this->assertStringContainsString(
                '[worker #2] Parallel analysis worker failed:',
                $runtimeException->getMessage()
            );
            $this->assertStringContainsString('worker exited with code 255', $runtimeException->getMessage());
            $this->assertStringContainsString('simulated worker fatal', $runtimeException->getMessage());
            $this->assertStringNotContainsString('invalid payload', $runtimeException->getMessage());
        } finally {
            $GLOBALS['mock_proc_open_stdout']  = null;
            $GLOBALS['mock_proc_open_command'] = null;
        }
    }

    public function testExtractThrowsWhenTempnamFails(): void
    {
        $GLOBALS['mock_tempnam'] = true;

        $dir  = $this->makeTemporaryDirectory('structarmed-parallel-test');
        $file = $dir . '/Foo.php';
        file_put_contents($file, '<?php class Foo {}');

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create temporary file for parallel analysis.');

        try {
            $parallelAnalysisNodeExtractor->extract([$file]);
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

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned an invalid payload.');

        try {
            $parallelAnalysisNodeExtractor->extract([$file]);
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

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker failed: simulated payload error');

        try {
            $parallelAnalysisNodeExtractor->extract([$file]);
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

        $parallelAnalysisNodeExtractor = new ParallelAnalysisNodeExtractor($dir, [], [], 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel analysis worker returned an invalid error payload.');

        try {
            $parallelAnalysisNodeExtractor->extract([$file]);
        } finally {
            $GLOBALS['mock_file_get_contents_payload'] = null;
            $GLOBALS['mock_tracked_tempnam_files']     = [];
        }
    }
}
