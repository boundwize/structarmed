<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\Analyser\ExtractionResult;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(ClassNodeExtractor::class)]
#[CoversClass(ExtractionResult::class)]
final class ClassNodeExtractorTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testExtractReturnsEmptyArrayForNoFiles(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'App\\Domain'], '/tmp');
        $classNodeExtractor     = new ClassNodeExtractor($namespaceLayerResolver);

        $result = $classNodeExtractor->extract([]);

        $this->assertSame([], $result);
    }

    public function testExtractReturnsClassNodesFromPhpFile(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-extractor-test');
        $file = $dir . '/Foo.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class Foo
{
}
PHP);

        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'App\\Domain'], $dir);
        $classNodeExtractor     = new ClassNodeExtractor($namespaceLayerResolver);

        $result = $classNodeExtractor->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ClassNode::class, $result[0]);
        $this->assertSame('App\\Domain\\Foo', $result[0]->className);
    }

    public function testExtractSkipsFilesWithParseErrors(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-extractor-test');
        $file = $dir . '/Invalid.php';

        file_put_contents($file, '<?php this is not valid php !!!!!');

        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'App\\Domain'], $dir);
        $classNodeExtractor     = new ClassNodeExtractor($namespaceLayerResolver);

        $result = $classNodeExtractor->extract([$file]);

        $this->assertSame([], $result);
    }

    public function testExtractSkipsFilesWithEmptyAst(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-extractor-test');
        $file = $dir . '/Empty.php';

        file_put_contents($file, '<?php');

        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'App\\Domain'], $dir);
        $classNodeExtractor     = new ClassNodeExtractor($namespaceLayerResolver);

        $result = $classNodeExtractor->extract([$file]);

        $this->assertSame([], $result);
    }

    public function testExtractWithFileAnalysesReturnsFactsFromTheSameParse(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-extractor-test');
        $file = $dir . '/Foo.php';

        file_put_contents($file, '<?php final class Foo {} echo "side effect";');

        $namespaceLayerResolver = new NamespaceLayerResolver(['Source' => ''], $dir);
        $extractionResult       = (new ClassNodeExtractor($namespaceLayerResolver))
            ->extractWithFileAnalyses([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertArrayHasKey($file, $extractionResult->fileAnalyses);
        $this->assertTrue($extractionResult->fileAnalyses[$file]->declaresSymbols);
        $this->assertTrue($extractionResult->fileAnalyses[$file]->hasSideEffects);
    }

    public function testExtractAdvancesProgressHandler(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-extractor-test');
        $file = $dir . '/Bar.php';

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Domain;

final class Bar
{
}
PHP);

        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'App\\Domain'], $dir);
        $classNodeExtractor     = new ClassNodeExtractor($namespaceLayerResolver);

        $advanced = [];

        $progressHandler = new class ($advanced) implements ProgressHandlerInterface {
            /** @param list<string> $advanced */
            public function __construct(
                /** @phpstan-ignore property.onlyWritten */
                private array &$advanced
            ) {
            }

            public function advance(string $file): void
            {
                $this->advanced[] = $file;
            }

            public function start(int $total): void
            {
            }

            public function finish(): void
            {
            }
        };

        $classNodeExtractor->extract([$file], $progressHandler);

        $this->assertCount(1, $advanced);
        $this->assertSame($file, $advanced[0]);
    }
}
