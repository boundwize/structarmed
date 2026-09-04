<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Scalar\AddNumericLiteralSeparatorsVisitor;
use Boundwize\StructArmed\Rule\Rules\File\LargeNumericLiteralMustUseSeparatorRule;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use Boundwize\StructArmed\Util\Path;
use PhpParser\Node;
use PhpParser\Node\Scalar\Float_;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_values;
use function file_get_contents;
use function file_put_contents;
use function iterator_to_array;
use function mkdir;
use function realpath;

#[CoversClass(LargeNumericLiteralMustUseSeparatorRule::class)]
#[CoversClass(AddNumericLiteralSeparatorsVisitor::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
final class LargeNumericLiteralMustUseSeparatorRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testFixesMultipleLiteralsWithoutChangingSurroundingCode(): void
    {
        $basePath                                = $this->makeProject(<<<'PHP'
<?php

// Preserve this comment.
$value = 1000000;
$other = 123456789;
$float = 1000500.001;
PHP);
        $largeNumericLiteralMustUseSeparatorRule = new LargeNumericLiteralMustUseSeparatorRule(
            sourcePaths: ['src/'],
        );
        $violations                              = $largeNumericLiteralMustUseSeparatorRule->evaluateProjectAll(
            $basePath,
            Architecture::define(),
        );

        $this->assertCount(3, $violations);

        foreach ($violations as $violation) {
            $this->assertTrue($largeNumericLiteralMustUseSeparatorRule->fix($violation));
        }

        $this->assertSame(<<<'PHP'
<?php

// Preserve this comment.
$value = 1_000_000;
$other = 123_456_789;
$float = 1_000_500.001;
PHP, file_get_contents($basePath . '/src/Foo.php'));
        $this->assertSame(
            [],
            $largeNumericLiteralMustUseSeparatorRule->evaluateProjectAll($basePath, Architecture::define()),
        );
    }

    public function testFixTargetsTheLiteralSpellingOnItsLine(): void
    {
        $basePath                                = $this->makeProject("<?php\n\n\$values = [1000000, 123456789];\n");
        $largeNumericLiteralMustUseSeparatorRule = new LargeNumericLiteralMustUseSeparatorRule(
            sourcePaths: ['src/'],
        );
        $violations                              = $largeNumericLiteralMustUseSeparatorRule->evaluateProjectAll(
            $basePath,
            Architecture::define(),
        );

        $this->assertCount(2, $violations);
        $this->assertEquals(
            $violations[0],
            $largeNumericLiteralMustUseSeparatorRule->evaluateProject($basePath, Architecture::define()),
        );
        $this->assertTrue($largeNumericLiteralMustUseSeparatorRule->fix($violations[1]));
        $this->assertSame(
            "<?php\n\n\$values = [1000000, 123_456_789];\n",
            file_get_contents($basePath . '/src/Foo.php'),
        );
        $this->assertTrue($largeNumericLiteralMustUseSeparatorRule->fix($violations[0]));
        $this->assertSame(
            "<?php\n\n\$values = [1_000_000, 123_456_789];\n",
            file_get_contents($basePath . '/src/Foo.php'),
        );
    }

    public function testPrintsReplacedFloatsWithoutARawValueSpelling(): void
    {
        $basePath = $this->makeProject("<?php\n\n\$value = 1.5;\n");
        $visitor  = new class extends NodeVisitorAbstract {
            public function leaveNode(Node $node): ?Node
            {
                return $node instanceof Float_ ? new Float_(2.5) : null;
            }
        };

        $this->assertTrue((new PhpParserFixerProcessor())->process($basePath . '/src/Foo.php', $visitor));
        $this->assertSame("<?php\n\n\$value = 2.5;\n", file_get_contents($basePath . '/src/Foo.php'));
    }

    public function testAnalyserPreservesFixInformationInSequentialAndParallelRuns(): void
    {
        $basePath                                = $this->makeProject("<?php\n\n\$value = 1000000;\n");
        $largeNumericLiteralMustUseSeparatorRule = new LargeNumericLiteralMustUseSeparatorRule(
            sourcePaths: ['src/'],
        );
        $architecture                            = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('numeric.separator', $largeNumericLiteralMustUseSeparatorRule);

        foreach ([AnalyserOptions::sequential(), AnalyserOptions::parallel(2)] as $options) {
            $violations = array_values(iterator_to_array(
                (new Analyser($basePath))->analyse($architecture, [], null, $options),
            ));

            $this->assertCount(1, $violations);
            $this->assertSame('numeric.separator', $violations[0]->ruleKey);
            $this->assertTrue($violations[0]->fixable);
            $this->assertSame('1000000', $violations[0]->numericLiteral);
        }

        $this->assertTrue($largeNumericLiteralMustUseSeparatorRule->fix($violations[0]));
        $this->assertSame("<?php\n\n\$value = 1_000_000;\n", file_get_contents($basePath . '/src/Foo.php'));
    }

    private function makeProject(string $code): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-numeric-separator');
        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', $code);

        $realBasePath = realpath($basePath);
        $this->assertIsString($realBasePath);

        return Path::normalise($realBasePath, canonicalise: true);
    }
}
