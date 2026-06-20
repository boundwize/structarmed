<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

#[CoversClass(FileAnalysis::class)]
#[CoversClass(FileAnalysisProvider::class)]
final class FileAnalysisProviderTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testAnalysesPsr1FactsAndCachesThemByFile(): void
    {
        $directory = $this->makeTemporaryDirectory('structarmed-file-analysis-test');
        $file      = $directory . '/Foo.php';

        file_put_contents($file, <<<'PHP'
            <?php

            final class Foo {}
            echo 'side effect';
            PHP);

        $fileAnalysisProvider = new FileAnalysisProvider();
        $fileAnalysis = $fileAnalysisProvider->analyse($file);

        $this->assertFalse($fileAnalysis->hasUtf8Bom);
        $this->assertTrue($fileAnalysis->hasValidUtf8);
        $this->assertNull($fileAnalysis->invalidPhpTagLine);
        $this->assertTrue($fileAnalysis->hasValidAst);
        $this->assertTrue($fileAnalysis->declaresSymbols);
        $this->assertTrue($fileAnalysis->hasSideEffects);
        $this->assertSame(4, $fileAnalysis->sideEffectLine);

        file_put_contents($file, '<? echo "changed";');

        $this->assertSame($fileAnalysis, $fileAnalysisProvider->analyse($file));
    }

    public function testReportsInvalidTagsAndInvalidAstWithoutThrowing(): void
    {
        $directory = $this->makeTemporaryDirectory('structarmed-file-analysis-test');
        $file      = $directory . '/Invalid.php';

        file_put_contents($file, "<? echo 'short';\n<?php this is invalid !!!!!");

        $fileAnalysis = (new FileAnalysisProvider())->analyse($file);

        $this->assertSame(1, $fileAnalysis->invalidPhpTagLine);
        $this->assertFalse($fileAnalysis->hasValidAst);
        $this->assertFalse($fileAnalysis->declaresSymbols);
        $this->assertFalse($fileAnalysis->hasSideEffects);
    }
}
