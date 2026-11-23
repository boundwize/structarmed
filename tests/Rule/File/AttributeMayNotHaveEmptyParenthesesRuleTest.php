<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Attribute\RemoveAttributeParenthesesVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Rules\File\AttributeMayNotHaveEmptyParenthesesRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use Boundwize\StructArmed\Util\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function iterator_to_array;
use function mkdir;
use function realpath;

#[CoversClass(AttributeMayNotHaveEmptyParenthesesRule::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(RemoveAttributeParenthesesVisitor::class)]
final class AttributeMayNotHaveEmptyParenthesesRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testViolatesAndFixesOnlyEmptyParentheses(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Doctrine\ORM\Mapping as ORM;

            #[ORM\Entity(), \Deprecated ( ), Plain, WithArgument(1), Commented(/* none */)]
            final class Foo
            {
                #[Before /* comment */ ()]
                public const A = 1;

                #[
                    Multi(),
                    Line(
                    ),
                ]
                public function run(#[\SensitiveParameter()] string $secret): object
                {
                    $closure = #[Pure()] static fn (): int => 1;

                    return new #[Anonymous()] class {
                    };
                }
            }

            PHP);

        $attributeMayNotHaveEmptyParenthesesRule = new AttributeMayNotHaveEmptyParenthesesRule(['src/']);
        $violations                              = $attributeMayNotHaveEmptyParenthesesRule->evaluateProjectAll(
            $basePath,
            Architecture::define()
        );

        $this->assertSame(
            [
                'Attribute [Doctrine\ORM\Mapping\Entity] may not have empty parentheses',
                'Attribute [Deprecated] may not have empty parentheses',
                'Attribute [App\Before] may not have empty parentheses',
                'Attribute [App\Multi] may not have empty parentheses',
                'Attribute [App\Line] may not have empty parentheses',
                'Attribute [SensitiveParameter] may not have empty parentheses',
                'Attribute [App\Pure] may not have empty parentheses',
                'Attribute [App\Anonymous] may not have empty parentheses',
            ],
            array_map(static fn (RuleViolation $ruleViolation): string => $ruleViolation->message, $violations)
        );
        $this->assertSame([9, 9, 12, 16, 17, 20, 22, 24], array_map(
            static fn (RuleViolation $ruleViolation): int => $ruleViolation->line,
            $violations
        ));
        $this->assertSame($basePath . '/src/Foo.php', $violations[0]->file);
        $this->assertSame('', $violations[0]->className);

        $this->assertTrue($attributeMayNotHaveEmptyParenthesesRule->fix(...$violations));
        $this->assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Doctrine\ORM\Mapping as ORM;

            #[ORM\Entity, \Deprecated, Plain, WithArgument(1), Commented(/* none */)]
            final class Foo
            {
                #[Before /* comment */]
                public const A = 1;

                #[
                    Multi,
                    Line,
                ]
                public function run(#[\SensitiveParameter] string $secret): object
                {
                    $closure = #[Pure] static fn (): int => 1;

                    return new #[Anonymous] class {
                    };
                }
            }

            PHP, file_get_contents($basePath . '/src/Foo.php'));
        $this->assertSame(
            [],
            $attributeMayNotHaveEmptyParenthesesRule->evaluateProjectAll($basePath, Architecture::define())
        );
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $attributeMayNotHaveEmptyParenthesesRule->evaluateProject($basePath, Architecture::define())
        );
    }

    public function testFixesEveryAttributeOnTheViolationLineAndLeavesOtherLinesAlone(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
            <?php

            #[First(), Second()]
            #[Third()]
            #[Fourth # why
                ()]
            function run(): void
            {
            }

            PHP);

        $attributeMayNotHaveEmptyParenthesesRule = new AttributeMayNotHaveEmptyParenthesesRule(['src/']);
        $violations                              = $attributeMayNotHaveEmptyParenthesesRule->evaluateProjectAll(
            $basePath,
            Architecture::define()
        );

        $this->assertSame([3, 3, 4, 5], array_map(
            static fn (RuleViolation $ruleViolation): int => $ruleViolation->line,
            $violations
        ));

        $this->assertTrue($attributeMayNotHaveEmptyParenthesesRule->fix($violations[0]));
        $this->assertSame(<<<'PHP'
            <?php

            #[First, Second]
            #[Third()]
            #[Fourth # why
                ()]
            function run(): void
            {
            }

            PHP, file_get_contents($basePath . '/src/Foo.php'));
        // The first violation's fix covered the whole line, so nothing is left to change.
        $this->assertFalse($attributeMayNotHaveEmptyParenthesesRule->fix($violations[1]));

        // The newline ending the `#` comment stays, or `]` would become part of it.
        $this->assertTrue($attributeMayNotHaveEmptyParenthesesRule->fix($violations[3]));
        $this->assertSame(<<<'PHP'
            <?php

            #[First, Second]
            #[Third()]
            #[Fourth # why
                ]
            function run(): void
            {
            }

            PHP, file_get_contents($basePath . '/src/Foo.php'));
    }

    public function testViolatesAndFixesAttributeOnGlobalConstant(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
            <?php

            namespace App;

            #[\Deprecated()]
            const LEGACY = 1;

            PHP);

        $attributeMayNotHaveEmptyParenthesesRule = new AttributeMayNotHaveEmptyParenthesesRule(['src/']);
        $violations                              = $attributeMayNotHaveEmptyParenthesesRule->evaluateProjectAll(
            $basePath,
            Architecture::define()
        );

        $this->assertCount(1, $violations);
        $this->assertSame(5, $violations[0]->line);
        $this->assertSame('Attribute [Deprecated] may not have empty parentheses', $violations[0]->message);

        $this->assertTrue($attributeMayNotHaveEmptyParenthesesRule->fix($violations[0]));
        $this->assertSame(<<<'PHP'
            <?php

            namespace App;

            #[\Deprecated]
            const LEGACY = 1;

            PHP, file_get_contents($basePath . '/src/Foo.php'));
    }

    public function testAnalyserReportsAndFixesThroughTheCollectedFileAnalysis(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
            <?php

            namespace App;

            final class Foo
            {
                #[\Override()]
                public function __toString(): string
                {
                    return '';
                }
            }

            PHP);

        $attributeMayNotHaveEmptyParenthesesRule = new AttributeMayNotHaveEmptyParenthesesRule(['src/']);
        $architecture                            = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('attribute.parentheses', $attributeMayNotHaveEmptyParenthesesRule);

        foreach ([AnalyserOptions::sequential(), AnalyserOptions::parallel(2)] as $analyserOptions) {
            $violations = array_values(iterator_to_array(
                (new Analyser($basePath))->analyse($architecture, [], null, $analyserOptions)
            ));

            $this->assertCount(1, $violations);
            $this->assertSame('attribute.parentheses', $violations[0]->ruleKey);
            $this->assertTrue($violations[0]->fixable);
            $this->assertSame(7, $violations[0]->line);
            $this->assertSame('Attribute [Override] may not have empty parentheses', $violations[0]->message);
        }

        $this->assertTrue($attributeMayNotHaveEmptyParenthesesRule->fix($violations[0]));
        $this->assertStringContainsString(
            "    #[\\Override]\n",
            (string) file_get_contents($basePath . '/src/Foo.php')
        );
        $this->assertCount(
            0,
            (new Analyser($basePath))->analyse($architecture, [], null, AnalyserOptions::sequential())
        );
    }

    private function makeProject(string $code): string
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-attribute-parentheses');
        mkdir($basePath . '/src');
        file_put_contents($basePath . '/src/Foo.php', $code);

        // Violations carry canonical, forward-slash paths: realpath() differs from the
        // temporary directory on macOS and uses backslashes on Windows.
        $realBasePath = realpath($basePath);
        $this->assertIsString($realBasePath);

        return Path::normalise($realBasePath, canonicalise: true);
    }
}
