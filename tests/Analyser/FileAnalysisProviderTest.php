<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;

#[CoversClass(FileAnalysis::class)]
#[CoversClass(FileAnalysisProvider::class)]
final class FileAnalysisProviderTest extends TestCase
{
    public function testAnalysesPsr1FactsAndCachesThemByFile(): void
    {
        $file = $this->source(<<<'PHP'
            <?php

            final class Foo {}
            echo 'side effect';
            PHP);

        $fileAnalysisProvider = new FileAnalysisProvider();
        $fileAnalysis         = $fileAnalysisProvider->analyse($file);

        $this->assertFalse($fileAnalysis->hasUtf8Bom);
        $this->assertTrue($fileAnalysis->hasValidUtf8);
        $this->assertNull($fileAnalysis->invalidPhpTagLine);
        $this->assertTrue($fileAnalysis->hasValidAst);
        $this->assertTrue($fileAnalysis->declaresSymbols);
        $this->assertTrue($fileAnalysis->hasSideEffects);
        $this->assertSame(4, $fileAnalysis->sideEffectLine);
        $this->assertIsArray($fileAnalysisProvider->ast($file));
        $this->assertFalse($fileAnalysisProvider->hasUtf8Bom($file));
        $this->assertTrue($fileAnalysisProvider->hasValidUtf8($file));
        $this->assertNull($fileAnalysisProvider->invalidPhpTagLine($file));

        $fileAnalysisProvider->releaseAst($file);

        $this->assertNull($fileAnalysisProvider->ast($file));
        $this->assertSame($fileAnalysis, $fileAnalysisProvider->analyse($file));
    }

    public function testReportsInvalidTagsAndInvalidAstWithoutThrowing(): void
    {
        $file = $this->source("<? echo 'short';\n<?php this is invalid !!!!!");

        $fileAnalysis = (new FileAnalysisProvider())->analyse($file);

        $this->assertSame(1, $fileAnalysis->invalidPhpTagLine);
        $this->assertFalse($fileAnalysis->hasValidAst);
        $this->assertFalse($fileAnalysis->declaresSymbols);
        $this->assertFalse($fileAnalysis->hasSideEffects);
    }

    public function testParsesAstWithoutRetainingItForAnalysis(): void
    {
        $fileAnalysisProvider = new FileAnalysisProvider();

        $this->assertIsArray($fileAnalysisProvider->ast($this->source('<?php final class Foo {}'), false));
        $this->assertNull($fileAnalysisProvider->ast($this->source('<?php invalid !!!!!'), false));
    }

    public function testReusesSeededAnalysisAcrossWindowsPathSeparators(): void
    {
        $windowsPath  = 'C:\\project\\src\\Foo.php';
        $fileAnalysis = new FileAnalysis(
            file: $windowsPath,
            hasUtf8Bom: false,
            hasValidUtf8: true,
            invalidPhpTagLine: null,
            hasValidAst: true,
            declaresSymbols: true,
            hasSideEffects: false,
            sideEffectLine: 1,
        );

        $fileAnalysisProvider = new FileAnalysisProvider([$windowsPath => $fileAnalysis]);

        $this->assertSame($fileAnalysis, $fileAnalysisProvider->analyse('C:/project/src/Foo.php'));
    }

    /** @return iterable<string, array{string, bool, bool, int|null}> */
    public static function lightweightAnalysisProvider(): iterable
    {
        yield 'UTF-8 BOM' => ["\xEF\xBB\xBF<?php echo 'ok';", true, true, null];
        yield 'invalid UTF-8' => ["<?php echo \"\xB1\";", false, false, null];
        yield 'short tag' => ['<? echo "short";', false, true, 1];
        yield 'uppercase tag' => ['<?PHP echo "upper";', false, true, 1];
        yield 'valid tag' => ['<?php echo "valid";', false, true, null];
        yield 'echo tag' => ['<?= "echo";', false, true, null];
        yield 'plain text' => ['plain text', false, true, null];
    }

    #[DataProvider('lightweightAnalysisProvider')]
    public function testLightweightChecksCacheContentsAndTagResults(
        string $contents,
        bool $hasUtf8Bom,
        bool $hasValidUtf8,
        ?int $invalidPhpTagLine,
    ): void {
        $file                 = $this->source($contents);
        $fileAnalysisProvider = new FileAnalysisProvider();

        $this->assertSame($hasUtf8Bom, $fileAnalysisProvider->hasUtf8Bom($file));
        $this->assertSame($hasValidUtf8, $fileAnalysisProvider->hasValidUtf8($file));
        $this->assertSame($invalidPhpTagLine, $fileAnalysisProvider->invalidPhpTagLine($file));
        $this->assertSame($invalidPhpTagLine, $fileAnalysisProvider->invalidPhpTagLine($file));
    }

    public function testRecognisesNeutralStatementsAndConditionalDeclarations(): void
    {
        $file = $this->source(<<<'PHP'
            <?php

            namespace App;

            declare(ticks=1);

            use Foo\Bar;
            use Foo\{Baz, Qux};

            ;

            function helper(): void {}

            if (true) {
                class Conditional {}
            }

            ?>

            <?php
            PHP);

        $fileAnalysis = (new FileAnalysisProvider())->analyse($file);

        $this->assertTrue($fileAnalysis->declaresSymbols);
        $this->assertFalse($fileAnalysis->hasSideEffects);
    }

    public function testTreatsNamespaceConstantAsSymbolDeclaration(): void
    {
        $file = $this->source(<<<'PHP'
            <?php

            namespace App;

            const VERSION = '1.0';

            final class Foo {}
            PHP);

        $fileAnalysis = (new FileAnalysisProvider())->analyse($file);

        $this->assertTrue($fileAnalysis->declaresSymbols);
        $this->assertFalse($fileAnalysis->hasSideEffects);
    }

    public function testRejectsConditionalDeclarationsWithBranchesOrSideEffects(): void
    {
        $elseIfFile = $this->source(<<<'PHP'
            <?php
            if (true) {
                class First {}
            } elseif (false) {
                class Second {}
            }
            PHP);
        $elseFile   = $this->source(<<<'PHP'
            <?php
            if (true) {
                class First {}
            } else {
                class Second {}
            }
            PHP);
        $effectFile = $this->source(<<<'PHP'
            <?php
            if (true) {
                echo 'effect';
            }
            PHP);

        $fileAnalysisProvider = new FileAnalysisProvider();

        $this->assertTrue($fileAnalysisProvider->analyse($elseIfFile)->hasSideEffects);
        $this->assertTrue($fileAnalysisProvider->analyse($elseFile)->hasSideEffects);
        $this->assertTrue($fileAnalysisProvider->analyse($effectFile)->hasSideEffects);
    }

    private function source(string $contents): string
    {
        return 'data://text/plain;base64,' . base64_encode($contents);
    }
}
