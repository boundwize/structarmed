<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Util\InlineHtmlOpeningTagMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;

#[CoversClass(FileAnalysis::class)]
#[CoversClass(FileAnalysisProvider::class)]
#[CoversClass(InlineHtmlOpeningTagMatcher::class)]
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

    public function testFiltersFilesToInitialAnalysedFileScopeWhenEnabled(): void
    {
        $fooFile      = $this->source('<?php final class Foo {}');
        $barFile      = $this->source('<?php final class Bar {}');
        $fileAnalysis = (new FileAnalysisProvider())->analyse($fooFile);

        $fileAnalysisProvider = new FileAnalysisProvider(
            analyses: [$fooFile => $fileAnalysis],
            isScopeFilesEnabled: true,
        );

        $fileAnalysisProvider->analyse($barFile);

        $this->assertSame(
            [$fooFile],
            $fileAnalysisProvider->filesInScope([$fooFile, $barFile]),
        );
    }

    public function testReturnsAllFilesWhenScopeIsDisabled(): void
    {
        $files = ['C:/project/src/Foo.php', 'C:/project/src/Bar.php'];

        $this->assertSame($files, (new FileAnalysisProvider())->filesInScope($files));
    }

    /** @return iterable<string, array{string, bool, bool, int|null}> */
    public static function lightweightAnalysisProvider(): iterable
    {
        yield 'UTF-8 BOM' => ["\xEF\xBB\xBF<?php echo 'ok';", true, true, null];
        yield 'invalid UTF-8' => ["<?php echo \"\xB1\";", false, false, null];
        yield 'short tag' => ['<? echo "short";', false, true, 1];
        yield 'uppercase tag' => ['<?PHP echo "upper";', false, true, 1];
        yield 'PHP-like inline HTML' => ['<?php?>', false, true, null];
        yield 'valid tag' => ['<?php echo "valid";', false, true, null];
        yield 'echo tag' => ['<?= "echo";', false, true, null];
        yield 'XML declaration' => ['<?xml version="1.0"?>', false, true, null];
        yield 'XML stylesheet' => ['<?xml-stylesheet href="style.xsl"?>', false, true, null];
        yield 'arbitrary XML processing instruction' => ['<?xml-custom value="x"?>', false, true, 1];
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

    public function testDetectsSideEffectsInsideDeclareBlock(): void
    {
        $file = $this->source(<<<'PHP'
            <?php

            declare(ticks=1) {
                echo 'side effect';
            }

            final class Foo {}
            PHP);

        $fileAnalysis = (new FileAnalysisProvider())->analyse($file);

        $this->assertTrue($fileAnalysis->declaresSymbols);
        $this->assertTrue($fileAnalysis->hasSideEffects);
        $this->assertSame(4, $fileAnalysis->sideEffectLine);
    }

    public function testReportsFirstSideEffectLineWhenMultipleSideEffectsExist(): void
    {
        $file = $this->source(<<<'PHP'
            <?php

            final class Foo {}
            echo 'first side effect';
            echo 'second side effect';
            echo 'third side effect';
            PHP);

        $fileAnalysis = (new FileAnalysisProvider())->analyse($file);

        $this->assertTrue($fileAnalysis->declaresSymbols);
        $this->assertTrue($fileAnalysis->hasSideEffects);
        $this->assertSame(4, $fileAnalysis->sideEffectLine);
    }

    public function testKeepsFirstSideEffectLineAcrossNamespaceBlocks(): void
    {
        $file = $this->source(<<<'PHP'
            <?php

            namespace First {
                echo 'first side effect';
            }

            namespace Second {
                echo 'second side effect';
            }
            PHP);

        $fileAnalysis = (new FileAnalysisProvider())->analyse($file);

        $this->assertTrue($fileAnalysis->hasSideEffects);
        $this->assertSame(4, $fileAnalysis->sideEffectLine);
    }

    public function testIfElseBranchesWithOnlyDeclarationsAreNotSideEffects(): void
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

        $fileAnalysis = $fileAnalysisProvider->analyse($elseIfFile);
        $this->assertFalse($fileAnalysis->hasSideEffects);
        $this->assertTrue($fileAnalysis->declaresSymbols);

        $elseAnalysis = $fileAnalysisProvider->analyse($elseFile);
        $this->assertFalse($elseAnalysis->hasSideEffects);
        $this->assertTrue($elseAnalysis->declaresSymbols);

        $this->assertTrue($fileAnalysisProvider->analyse($effectFile)->hasSideEffects);
    }

    /** @return iterable<string, array{string, bool, bool, int}> */
    public static function conditionExpressionProvider(): iterable
    {
        yield 'include in condition' => [
            <<<'PHP'
            <?php
            if (include 'bootstrap.php') {
                class Foo {}
            }
            PHP,
            true,
            true,
            2,
        ];

        yield 'assignment in condition' => [
            <<<'PHP'
            <?php
            if ($bootstrapped = boot()) {
                class Foo {}
            }
            PHP,
            true,
            true,
            2,
        ];

        yield 'post decrement in condition' => [
            <<<'PHP'
            <?php
            if ($attempts--) {
                class Foo {}
            }
            PHP,
            true,
            true,
            2,
        ];

        yield 'require in elseif condition' => [
            <<<'PHP_WRAP'
            <?php
            if (PHP_VERSION_ID >= 80400) {
                class Modern {}
            } elseif (require 'legacy.php') {
                class Legacy {}
            }
            PHP_WRAP,
            true,
            true,
            4,
        ];

        yield 'function_exists guard' => [
            <<<'PHP'
            <?php
            if (! function_exists('helper')) {
                function helper(): void {}
            }
            PHP,
            true,
            false,
            1,
        ];

        yield 'version guard' => [
            <<<'PHP_WRAP'
            <?php
            if (PHP_VERSION_ID >= 80400) {
                function modern(): void {}
            }
            PHP_WRAP,
            true,
            false,
            1,
        ];

        yield 'closure body in condition is not executed' => [
            <<<'PHP'
            <?php
            if (is_callable(static function (): int {
                $count = 1;

                return $count;
            })) {
                class Foo {}
            }
            PHP,
            true,
            false,
            1,
        ];

        yield 'condition side effect precedes branch side effect' => [
            <<<'PHP'
            <?php
            if (include 'bootstrap.php') {
                echo 'booted';
            }
            class Foo {}
            PHP,
            true,
            true,
            2,
        ];

        yield 'branch side effect precedes elseif condition side effect' => [
            <<<'PHP'
            <?php
            if (true) {
                echo 'booted';
            } elseif (include 'bootstrap.php') {
                class Foo {}
            }
            PHP,
            true,
            true,
            3,
        ];

        yield 'earlier top level side effect wins' => [
            <<<'PHP'
            <?php
            echo 'booted';
            if (include 'bootstrap.php') {
                class Foo {}
            }
            PHP,
            true,
            true,
            2,
        ];
    }

    #[DataProvider('conditionExpressionProvider')]
    public function testDetectsSideEffectsInConditionExpressionsOfConditionalDeclarations(
        string $contents,
        bool $declaresSymbols,
        bool $hasSideEffects,
        int $sideEffectLine,
    ): void {
        $fileAnalysis = (new FileAnalysisProvider())->analyse($this->source($contents));

        $this->assertSame($declaresSymbols, $fileAnalysis->declaresSymbols);
        $this->assertSame($hasSideEffects, $fileAnalysis->hasSideEffects);
        $this->assertSame($sideEffectLine, $fileAnalysis->sideEffectLine);
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function defineStatementProvider(): iterable
    {
        yield 'defined() || define()' => ["defined('X') || define('X', 1);", true, false];
        yield '! defined() && define()' => ["! defined('X') && define('X', 1);", true, false];
        yield 'defined() or define()' => ["defined('X') or define('X', 1);", true, false];
        yield '! defined() and define()' => ["! defined('X') and define('X', 1);", true, false];
        yield 'define() || defined()' => ["define('X', 1) || defined('X');", true, false];
        yield 'nested defined() conditions' => ["defined('A') && defined('B') && define('C', 1);", true, false];
        yield 'unrelated call guarding define()' => ["foo() || define('X', 1);", false, true];
        yield 'defined() guarding unrelated call' => ["defined('X') || foo();", false, true];
        yield 'static define() call' => ["Foo::define('X', 1);", false, true];
        yield 'define() method call' => ["\$container->define('X', 1);", false, true];
        yield 'defined() on a variable' => ["\$guard || define('X', 1);", false, true];
        yield 'assignment' => ['$version = 1;', false, true];
    }

    #[DataProvider('defineStatementProvider')]
    public function testDistinguishesGuardedDefineDeclarationsFromSideEffects(
        string $statement,
        bool $declaresSymbols,
        bool $hasSideEffects,
    ): void {
        $file = $this->source("<?php\n" . $statement . "\n");

        $fileAnalysis = (new FileAnalysisProvider())->analyse($file);

        $this->assertSame($declaresSymbols, $fileAnalysis->declaresSymbols);
        $this->assertSame($hasSideEffects, $fileAnalysis->hasSideEffects);
    }

    private function source(string $contents): string
    {
        return 'data://text/plain;base64,' . base64_encode($contents);
    }
}
