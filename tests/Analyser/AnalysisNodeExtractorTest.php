<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\AnalysisNodeExtractor;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ExtractionResult;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(AnalysisNodeExtractor::class)]
#[CoversClass(ExtractionResult::class)]
final class AnalysisNodeExtractorTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testExtractReturnsEmptyArrayForNoFiles(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'App\\Domain'], '/tmp');
        $analysisNodeExtractor  = new AnalysisNodeExtractor($namespaceLayerResolver);

        $extractionResult = $analysisNodeExtractor->extract([]);

        $this->assertSame([], $extractionResult->classNodes);
        $this->assertSame([], $extractionResult->fileAnalyses);
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
        $analysisNodeExtractor  = new AnalysisNodeExtractor($namespaceLayerResolver);

        $extractionResult = $analysisNodeExtractor->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertInstanceOf(ClassNode::class, $extractionResult->classNodes[0]);
        $this->assertSame('App\\Domain\\Foo', $extractionResult->classNodes[0]->className);
    }

    public function testExtractSkipsFilesWithParseErrors(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-extractor-test');
        $file = $dir . '/Invalid.php';

        file_put_contents($file, '<?php this is not valid php !!!!!');

        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'App\\Domain'], $dir);
        $analysisNodeExtractor  = new AnalysisNodeExtractor($namespaceLayerResolver);

        $extractionResult = $analysisNodeExtractor->extract([$file]);

        $this->assertSame([], $extractionResult->classNodes);
    }

    public function testExtractSkipsFilesWithEmptyAst(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-extractor-test');
        $file = $dir . '/Empty.php';

        file_put_contents($file, '<?php');

        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'App\\Domain'], $dir);
        $analysisNodeExtractor  = new AnalysisNodeExtractor($namespaceLayerResolver);

        $extractionResult = $analysisNodeExtractor->extract([$file]);

        $this->assertSame([], $extractionResult->classNodes);
    }

    public function testExtractReturnsFactsFromTheSameParse(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-extractor-test');
        $file = $dir . '/Foo.php';

        file_put_contents($file, '<?php final class Foo {} echo "side effect";');

        $namespaceLayerResolver = new NamespaceLayerResolver(['Source' => ''], $dir);
        $extractionResult       = (new AnalysisNodeExtractor($namespaceLayerResolver))
            ->extract([$file]);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertArrayHasKey($file, $extractionResult->fileAnalyses);
        $this->assertTrue($extractionResult->fileAnalyses[$file]->declaresSymbols);
        $this->assertTrue($extractionResult->fileAnalyses[$file]->hasSideEffects);
    }

    public function testExtractSkipsFileAnalysisWhenItIsNotRequested(): void
    {
        $dir  = $this->makeTemporaryDirectory('structarmed-extractor-test');
        $file = $dir . '/Foo.php';

        file_put_contents($file, '<?php final class Foo {}');

        $namespaceLayerResolver = new NamespaceLayerResolver(['Source' => ''], $dir);
        $extractionResult       = (new AnalysisNodeExtractor($namespaceLayerResolver))
            ->extract([$file], withFileAnalysis: false);

        $this->assertCount(1, $extractionResult->classNodes);
        $this->assertSame([], $extractionResult->fileAnalyses);
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
        $analysisNodeExtractor  = new AnalysisNodeExtractor($namespaceLayerResolver);

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

        $analysisNodeExtractor->extract([$file], $progressHandler);

        $this->assertCount(1, $advanced);
        $this->assertSame($file, $advanced[0]);
    }
}
