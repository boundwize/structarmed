<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ConstFetch\LowercaseKeywordConstantVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Rules\File\MustUseLowercaseKeywordConstantRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function iterator_to_array;
use function mkdir;
use function realpath;
use function strtolower;

#[CoversClass(MustUseLowercaseKeywordConstantRule::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(LowercaseKeywordConstantVisitor::class)]
final class MustUseLowercaseKeywordConstantRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustUseLowercaseKeywordConstantRule(['src/']));
    }

    #[DataProvider('unqualifiedSpellingProvider')]
    public function testViolatesAndFixesUnqualifiedSpelling(string $spelling): void
    {
        $basePath                            = $this->makeProject("<?php\n\n\$value = " . $spelling . ";\n");
        $mustUseLowercaseKeywordConstantRule = new MustUseLowercaseKeywordConstantRule(['src/']);

        $violations = $mustUseLowercaseKeywordConstantRule->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertSame(3, $violations[0]->line);
        $this->assertSame($basePath . '/src/Foo.php', $violations[0]->file);
        $this->assertSame(
            'Keyword constant [' . $spelling . '] must use lowercase [' . strtolower($spelling) . ']',
            $violations[0]->message
        );
        $this->assertSame($spelling, $violations[0]->constantName);

        $this->assertTrue($mustUseLowercaseKeywordConstantRule->fix($violations[0]));
        $this->assertSame(
            "<?php\n\n\$value = " . strtolower($spelling) . ";\n",
            file_get_contents($basePath . '/src/Foo.php')
        );
        $this->assertSame(
            [],
            $mustUseLowercaseKeywordConstantRule->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    /** @return iterable<string, array{string}> */
    public static function unqualifiedSpellingProvider(): iterable
    {
        foreach (['TRUE', 'True', 'tRuE', 'FALSE', 'False', 'fAlSe', 'NULL', 'Null', 'nUlL'] as $spelling) {
            yield $spelling => [$spelling];
        }
    }

    #[DataProvider('unqualifiedSpellingProvider')]
    public function testViolatesAndFixesFullyQualifiedSpellingKeepingTheLeadingBackslash(string $spelling): void
    {
        $basePath                            = $this->makeProject("<?php\n\n\$value = \\" . $spelling . ";\n");
        $mustUseLowercaseKeywordConstantRule = new MustUseLowercaseKeywordConstantRule(['src/']);

        $violations = $mustUseLowercaseKeywordConstantRule->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertSame(3, $violations[0]->line);
        $this->assertSame(
            'Keyword constant [\\' . $spelling . '] must use lowercase [\\' . strtolower($spelling) . ']',
            $violations[0]->message
        );
        $this->assertSame($spelling, $violations[0]->constantName);

        $this->assertTrue($mustUseLowercaseKeywordConstantRule->fix($violations[0]));
        $this->assertSame(
            "<?php\n\n\$value = \\" . strtolower($spelling) . ";\n",
            file_get_contents($basePath . '/src/Foo.php')
        );
    }

    public function testPassesCanonicalSpellings(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
<?php

true;
false;
null;

\true;
\false;
\null;
PHP);

        $mustUseLowercaseKeywordConstantRule = new MustUseLowercaseKeywordConstantRule(['src/']);

        $this->assertSame(
            [],
            $mustUseLowercaseKeywordConstantRule->evaluateProjectAll($basePath, Architecture::define())
        );
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustUseLowercaseKeywordConstantRule->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testIgnoresUnrelatedConstants(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
<?php

FOO;
MY_CONSTANT;
\FOO;
Foo\BAR;
SomeClass::FOO;
PHP);

        $violations = (new MustUseLowercaseKeywordConstantRule(['src/']))
            ->evaluateProjectAll($basePath, Architecture::define());

        $this->assertSame([], $violations);
        $this->assertStringContainsString('SomeClass::FOO;', (string) file_get_contents($basePath . '/src/Foo.php'));
    }

    public function testReportsUnqualifiedSpellingInsideNamespace(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
<?php

namespace App;

function foo()
{
    return TRUE;
}
PHP);

        $violations = (new MustUseLowercaseKeywordConstantRule(['src/']))
            ->evaluateProjectAll($basePath, Architecture::define());

        $this->assertCount(1, $violations);
        $this->assertSame(7, $violations[0]->line);
        $this->assertSame('Keyword constant [TRUE] must use lowercase [true]', $violations[0]->message);
    }

    public function testFixesNestedExpressionsPreservingSurroundingCode(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
<?php

function foo($value)
{
    // keep this comment
    $value = NULL;

    foo(FALSE);

    $result = \TRUE ? \NULL : \FALSE;

    return TRUE;
}
PHP);

        $mustUseLowercaseKeywordConstantRule = new MustUseLowercaseKeywordConstantRule(['src/']);
        $violations                          = $mustUseLowercaseKeywordConstantRule->evaluateProjectAll(
            $basePath,
            Architecture::define()
        );

        $this->assertSame(
            [
                'Keyword constant [NULL] must use lowercase [null]',
                'Keyword constant [FALSE] must use lowercase [false]',
                'Keyword constant [\\TRUE] must use lowercase [\\true]',
                'Keyword constant [\\NULL] must use lowercase [\\null]',
                'Keyword constant [\\FALSE] must use lowercase [\\false]',
                'Keyword constant [TRUE] must use lowercase [true]',
            ],
            array_map(static fn (RuleViolation $ruleViolation): string => $ruleViolation->message, $violations)
        );
        $this->assertSame([6, 8, 10, 10, 10, 12], array_map(
            static fn (RuleViolation $ruleViolation): int => $ruleViolation->line,
            $violations
        ));

        foreach ($violations as $violation) {
            $this->assertTrue($mustUseLowercaseKeywordConstantRule->fix($violation));
        }

        $this->assertSame(<<<'PHP'
<?php

function foo($value)
{
    // keep this comment
    $value = null;

    foo(false);

    $result = \true ? \null : \false;

    return true;
}
PHP, file_get_contents($basePath . '/src/Foo.php'));
    }

    public function testFixesMultipleViolationsInOneFile(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
<?php

if (TRUE) {
    return \NULL;
}

return FALSE;
PHP);

        $mustUseLowercaseKeywordConstantRule = new MustUseLowercaseKeywordConstantRule(['src/']);
        $violations                          = $mustUseLowercaseKeywordConstantRule->evaluateProjectAll(
            $basePath,
            Architecture::define()
        );

        $this->assertCount(3, $violations);

        foreach ($violations as $violation) {
            $this->assertTrue($mustUseLowercaseKeywordConstantRule->fix($violation));
        }

        $this->assertSame(<<<'PHP'
<?php

if (true) {
    return \null;
}

return false;
PHP, file_get_contents($basePath . '/src/Foo.php'));
        $this->assertSame(
            [],
            $mustUseLowercaseKeywordConstantRule->evaluateProjectAll($basePath, Architecture::define())
        );
    }

    public function testFixesIdenticalSpellingsOnTheSameLineInOnePass(): void
    {
        $basePath                            = $this->makeProject("<?php\n\n\$value = TRUE && TRUE;\n");
        $mustUseLowercaseKeywordConstantRule = new MustUseLowercaseKeywordConstantRule(['src/']);
        $violations                          = $mustUseLowercaseKeywordConstantRule->evaluateProjectAll(
            $basePath,
            Architecture::define()
        );

        $this->assertCount(2, $violations);
        $this->assertTrue($mustUseLowercaseKeywordConstantRule->fix($violations[0]));
        $this->assertSame("<?php\n\n\$value = true && true;\n", file_get_contents($basePath . '/src/Foo.php'));
        // The second violation was fixed by the first pass, so nothing is left to change.
        $this->assertFalse($mustUseLowercaseKeywordConstantRule->fix($violations[1]));
    }

    public function testSkipsFilesWithParseErrors(): void
    {
        $basePath = $this->makeProject('<?php TRUE invalid !!!!!');

        $violations = (new MustUseLowercaseKeywordConstantRule(['src/']))
            ->evaluateProjectAll($basePath, Architecture::define());

        $this->assertSame([], $violations);
    }

    public function testEvaluatesProvidedFileAnalysesWithinSourcePaths(): void
    {
        $basePath = $this->makeProject("<?php\n\nreturn TRUE;\n");
        mkdir($basePath . '/tests');
        file_put_contents($basePath . '/tests/Bar.php', "<?php\n\nreturn NULL;\n");

        $sourceFile           = $basePath . '/src/Foo.php';
        $testFile             = $basePath . '/tests/Bar.php';
        $fileAnalysisProvider = FileAnalysisProvider::forScope(
            [
                $sourceFile => $this->makeFileAnalysis($sourceFile, [[3, '\\TRUE'], [9, 'Null']]),
                $testFile   => $this->makeFileAnalysis($testFile, [[3, 'NULL']]),
            ],
            [$sourceFile, $testFile],
        );

        $violations = (new MustUseLowercaseKeywordConstantRule(['src/']))->evaluateProjectAllWithProvider(
            $basePath,
            Architecture::define(),
            $fileAnalysisProvider,
        );

        $this->assertCount(2, $violations);
        $this->assertSame($sourceFile, $violations[0]->file);
        $this->assertSame(3, $violations[0]->line);
        $this->assertSame('Keyword constant [\\TRUE] must use lowercase [\\true]', $violations[0]->message);
        $this->assertSame('TRUE', $violations[0]->constantName);
        $this->assertSame(9, $violations[1]->line);
        $this->assertSame('Null', $violations[1]->constantName);
    }

    public function testFixWithoutConstantNameLowercasesFirstKeywordConstantOnLine(): void
    {
        $basePath                            = $this->makeProject("<?php\n\n\$value = FOO ?? Null;\n");
        $mustUseLowercaseKeywordConstantRule = new MustUseLowercaseKeywordConstantRule(['src/']);

        $this->assertTrue($mustUseLowercaseKeywordConstantRule->fix(new RuleViolation(
            message:   'Keyword constant [Null] must use lowercase [null]',
            file:      $basePath . '/src/Foo.php',
            line:      3,
            className: '',
        )));
        $this->assertSame("<?php\n\n\$value = FOO ?? null;\n", file_get_contents($basePath . '/src/Foo.php'));
    }

    public function testFixReturnsFalseForMissingFile(): void
    {
        $mustUseLowercaseKeywordConstantRule = new MustUseLowercaseKeywordConstantRule(['src/']);

        $this->assertFalse($mustUseLowercaseKeywordConstantRule->fix(new RuleViolation(
            message:   'Keyword constant [TRUE] must use lowercase [true]',
            file:      '/missing/Foo.php',
            line:      1,
            className: '',
        )));
    }

    public function testAnalyserReportsAndFixesThroughTheCollectedFileAnalysis(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
<?php

namespace App;

final class Foo
{
    public function bar(): ?bool
    {
        return \TRUE ? Null : false;
    }
}
PHP);

        $mustUseLowercaseKeywordConstantRule = new MustUseLowercaseKeywordConstantRule(['src/']);
        $architecture                        = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('keyword.lowercase', $mustUseLowercaseKeywordConstantRule);

        foreach ([AnalyserOptions::sequential(), AnalyserOptions::parallel(2)] as $analyserOptions) {
            $violations = array_values(iterator_to_array(
                (new Analyser($basePath))->analyse($architecture, [], null, $analyserOptions)
            ));

            $this->assertCount(2, $violations);
            $this->assertSame('keyword.lowercase', $violations[0]->ruleKey);
            $this->assertTrue($violations[0]->fixable);
            $this->assertSame(9, $violations[0]->line);
            $this->assertSame('Keyword constant [\\TRUE] must use lowercase [\\true]', $violations[0]->message);
            $this->assertSame('Keyword constant [Null] must use lowercase [null]', $violations[1]->message);
        }

        foreach ($violations as $violation) {
            $this->assertTrue($mustUseLowercaseKeywordConstantRule->fix($violation));
        }

        $this->assertStringContainsString(
            'return \true ? null : false;',
            (string) file_get_contents($basePath . '/src/Foo.php')
        );
        $this->assertCount(
            0,
            (new Analyser($basePath))->analyse($architecture, [], null, AnalyserOptions::sequential())
        );
    }

    private function makeProject(string $code): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-keyword-constant');
        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', $code);

        // Violations carry canonical paths, which differ from the temporary directory on macOS.
        $realBasePath = realpath($basePath);
        $this->assertIsString($realBasePath);

        return $realBasePath;
    }

    /** @param list<array{int, string}> $nonCanonicalKeywordConstants */
    private function makeFileAnalysis(string $file, array $nonCanonicalKeywordConstants): FileAnalysis
    {
        return new FileAnalysis(
            file: $file,
            hasUtf8Bom: false,
            hasValidUtf8: true,
            invalidPhpTagLine: null,
            hasValidAst: true,
            declaresSymbols: false,
            hasSideEffects: true,
            sideEffectLine: 3,
            nonCanonicalKeywordConstants: $nonCanonicalKeywordConstants,
        );
    }
}
