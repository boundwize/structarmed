<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Util\InlineHtmlOpeningTagMatcher;
use Boundwize\StructArmed\Util\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function base64_encode;
use function basename;
use function dirname;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

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

    public function testReusesAstParsedBeforeAnalysis(): void
    {
        $file = $this->source(<<<'PHP'
            <?php

            final class Foo {}
            PHP);

        $fileAnalysisProvider = new FileAnalysisProvider();
        $ast                  = $fileAnalysisProvider->ast($file);

        $this->assertIsArray($ast);
        $this->assertSame($ast, $fileAnalysisProvider->ast($file));

        $fileAnalysis = $fileAnalysisProvider->analyse($file);

        $this->assertTrue($fileAnalysis->hasValidAst);
        $this->assertTrue($fileAnalysis->declaresSymbols);
        $this->assertFalse($fileAnalysis->hasSideEffects);
        $this->assertNull($fileAnalysis->invalidPhpTagLine);

        $fileAnalysisProvider->releaseAst($file);

        $this->assertNull($fileAnalysisProvider->ast($file));
        $this->assertSame($fileAnalysis, $fileAnalysisProvider->analyse($file));
    }

    public function testReleaseAstDropsInvalidPhpTagLineCache(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'structarmed');
        file_put_contents($file, '<?php final class Foo {}');

        try {
            $fileAnalysisProvider = new FileAnalysisProvider();

            $this->assertIsArray($fileAnalysisProvider->ast($file));
            $this->assertNull($fileAnalysisProvider->invalidPhpTagLine($file));

            $fileAnalysisProvider->releaseAst($file);
            file_put_contents($file, "<? echo 'changed';");

            $this->assertSame(1, $fileAnalysisProvider->invalidPhpTagLine($file));
        } finally {
            unlink($file);
        }
    }

    public function testResolvesNamesBeforeFileStateWhenUsedStandalone(): void
    {
        $file = $this->source(<<<'PHP'
            <?php

            namespace App;

            use function Vendor\define;

            final class Service
            {
            }

            define('FOO', 'bar');
            PHP);

        $fileAnalysis = (new FileAnalysisProvider())->analyse($file);

        $this->assertTrue($fileAnalysis->declaresSymbols);
        $this->assertTrue($fileAnalysis->hasSideEffects);
        $this->assertSame(11, $fileAnalysis->sideEffectLine);
    }

    public function testTrustsReplacedResolvedAstWithoutResolvingNamesAgain(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'structarmed');
        file_put_contents($file, <<<'PHP'
            <?php

            namespace App;

            use function Vendor\define;

            final class Service
            {
            }

            define('FOO', 'bar');
            PHP);

        try {
            $fileAnalysisProvider = new FileAnalysisProvider();
            $ast                  = $fileAnalysisProvider->ast($file);

            $this->assertIsArray($ast);

            // The parsed AST is handed back unresolved through a non-canonical path: were
            // analyse() to run NameResolver again, the imported define() would resolve to
            // Vendor\define and count as a side effect.
            $fileAnalysisProvider->replaceResolvedAst(dirname($file) . '/./' . basename($file), $ast);

            $this->assertSame($ast, $fileAnalysisProvider->ast($file));

            $fileAnalysis = $fileAnalysisProvider->analyse($file);

            $this->assertTrue($fileAnalysis->declaresSymbols);
            $this->assertFalse($fileAnalysis->hasSideEffects);

            // Token-dependent facts still come from the parse behind the replaced AST.
            $this->assertContains('define', array_column($fileAnalysisProvider->tokens(), 'text'));
            $this->assertNull($fileAnalysisProvider->invalidPhpTagLine($file));

            $fileAnalysisProvider->releaseAst($file);

            $this->assertNull($fileAnalysisProvider->ast($file));
        } finally {
            unlink($file);
        }
    }

    public function testUsesSuppliedLocalFunctionsInsteadOfWalkingTheAstForThem(): void
    {
        $file = $this->source(<<<'PHP'
            <?php

            namespace App;

            define('FOO', 'bar');
            PHP);

        // Walked by the provider, the file declares no App\define(), so the call
        // is the global built-in and counts as a symbol declaration.
        $this->assertFalse((new FileAnalysisProvider())->analyse($file)->hasSideEffects);

        // Supplied names take precedence: told the file declares App\define(),
        // the same call is a local function call and therefore a side effect.
        $fileAnalysis = (new FileAnalysisProvider())->analyse($file, localFunctions: ['app\\define' => true]);

        $this->assertFalse($fileAnalysis->declaresSymbols);
        $this->assertTrue($fileAnalysis->hasSideEffects);
        $this->assertSame(5, $fileAnalysis->sideEffectLine);
    }

    public function testResolvesNamesAgainAfterReplacedResolvedAstIsReleased(): void
    {
        $file = $this->source(<<<'PHP'
            <?php

            namespace App;

            use function Vendor\define;

            define('FOO', 'bar');
            PHP);

        $fileAnalysisProvider = new FileAnalysisProvider();
        $ast                  = $fileAnalysisProvider->ast($file);

        $this->assertIsArray($ast);

        $fileAnalysisProvider->replaceResolvedAst($file, $ast);
        $fileAnalysisProvider->releaseAst($file);

        $this->assertTrue($fileAnalysisProvider->analyse($file)->hasSideEffects);
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

    public function testExposesTokensOfTheFileParsedLast(): void
    {
        $fileAnalysisProvider = new FileAnalysisProvider();

        $this->assertIsArray($fileAnalysisProvider->ast($this->source('<?php $foo = new class () {};')));

        $tokenTexts = [];

        foreach ($fileAnalysisProvider->tokens() as $token) {
            $tokenTexts[] = $token->text;
        }

        $this->assertContains('class', $tokenTexts);
        $this->assertContains('(', $tokenTexts);

        // The next parse replaces them.
        $this->assertIsArray($fileAnalysisProvider->ast($this->source('<?php $bar = 1;'), false));
        $this->assertNotContains('class', array_column($fileAnalysisProvider->tokens(), 'text'));
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

    public function testExposesInitialScopeInAnalyserOrder(): void
    {
        $fooFile      = $this->source('<?php final class Foo {}');
        $barFile      = $this->source('<?php final class Bar {}');
        $analyses     = new FileAnalysisProvider();
        $fileAnalysis = $analyses->analyse($fooFile);
        $barAnalysis  = $analyses->analyse($barFile);

        $this->assertSame([], (new FileAnalysisProvider())->scopeFiles());

        $fileAnalysisProvider = FileAnalysisProvider::forScope(
            [$fooFile => $fileAnalysis, $barFile => $barAnalysis],
            [$barFile, '/missing.php', $fooFile],
        );
        $scopeFiles           = [Path::normalise($barFile), Path::normalise($fooFile)];

        $this->assertSame($scopeFiles, $fileAnalysisProvider->scopeFiles());
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
        // Checked twice: the second call is served from the provider's cache.
        $firstInvalidPhpTagLine  = $fileAnalysisProvider->invalidPhpTagLine($file);
        $cachedInvalidPhpTagLine = $fileAnalysisProvider->invalidPhpTagLine($file);

        $this->assertSame($invalidPhpTagLine, $firstInvalidPhpTagLine);
        $this->assertSame($invalidPhpTagLine, $cachedInvalidPhpTagLine);
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

    /** @return iterable<string, array{string, bool, int}> */
    public static function conditionalExpressionProvider(): iterable
    {
        yield 'effectful condition after declaration' => [
            <<<'PHP'
                <?php
                final class Existing {}
                if (include 'bootstrap.php') {
                    class Conditional {}
                }
                PHP,
            true,
            3,
        ];
        yield 'assignment' => [
            <<<'PHP'
                <?php
                if ($enabled = true) {
                    class Conditional {}
                }
                PHP,
            true,
            2,
        ];
        yield 'include nested in an argument' => [
            <<<'PHP'
                <?php
                if (is_bool(include 'bootstrap.php')) {
                    class Conditional {}
                }
                PHP,
            true,
            2,
        ];
        yield 'effectful elseif after neutral condition' => [
            <<<'PHP'
                <?php
                if (true) {
                    class First {}
                } elseif (include 'bootstrap.php') {
                    class Second {}
                }
                PHP,
            true,
            4,
        ];
        yield 'multiple effectful conditions' => [
            <<<'PHP'
                <?php
                if (include 'first.php') {
                    class First {}
                } elseif (include 'second.php') {
                    class Second {}
                }
                PHP,
            true,
            2,
        ];
        yield 'multiple effects in one condition' => [
            <<<'PHP'
                <?php
                if ((include 'first.php') && (include 'second.php')) {
                    class Conditional {}
                }
                PHP,
            true,
            2,
        ];
        yield 'effectful condition and branch' => [
            <<<'PHP'
                <?php
                if (include 'bootstrap.php') {
                    echo 'side effect';
                    class Conditional {}
                }
                PHP,
            true,
            2,
        ];
        yield 'closure body is not evaluated' => [
            <<<'PHP'
                <?php
                if (static function (): bool {
                    return $enabled = true;
                }) {
                    class Conditional {}
                }
                PHP,
            false,
            1,
        ];
    }

    #[DataProvider('conditionalExpressionProvider')]
    public function testAnalysesConditionalExpressions(
        string $contents,
        bool $hasSideEffects,
        int $sideEffectLine,
    ): void {
        $fileAnalysis = (new FileAnalysisProvider())->analyse($this->source($contents));

        $this->assertTrue($fileAnalysis->declaresSymbols);
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
        yield 'define() with include argument' => ["define('X', include 'config.php');", true, true];
        yield 'define() with assignment argument' => ["define('X', \$config = 1);", true, true];
        yield 'guarded define() with include argument' => [
            "defined('X') || define('X', include 'config.php');",
            true,
            true,
        ];
        yield 'fully qualified \\define()' => ["\\define('X', 1);", true, false];
        yield 'fully qualified \\define() in a namespace' => ["namespace App;\n\\define('X', 1);", true, false];
        yield 'unqualified define() in a namespace' => ["namespace App;\ndefine('X', 1);", true, false];
        yield 'imported define() in a namespace' => [
            "namespace App;\nuse function Vendor\\define;\ndefine('X', 1);",
            false,
            true,
        ];
        yield 'imported define() guarded by defined()' => [
            "namespace App;\nuse function Vendor\\define;\ndefined('X') || define('X', 1);",
            false,
            true,
        ];
        yield 'define() declared by the file in its namespace' => [
            "namespace App;\nfunction Define(string \$name, mixed \$value): void {}\ndefine('X', 1);",
            true,
            true,
        ];
        yield 'define() declared by the file in a namespace block' => [
            "namespace App {\n    function define(string \$name, mixed \$value): void {}\n    define('X', 1);\n}",
            true,
            true,
        ];
        yield 'define() declared by the file in another namespace' => [
            "namespace Other {\n    function define(string \$name, mixed \$value): void {}\n}\n"
            . "namespace App {\n    define('X', 1);\n}",
            true,
            false,
        ];
        yield 'defined() declared by the file guarding define()' => [
            "namespace App;\nfunction defined(string \$name): bool { return false; }\ndefined('X') || define('X', 1);",
            true,
            true,
        ];
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
