<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\Analyser;
use Boundwize\StructArmed\Analyser\AnalyserOptions;
use Boundwize\StructArmed\Analyser\AnonymousClassNode;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\RemoveAnonymousClassParenthesesVisitor;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\PhpParserFixerProcessor;
use Boundwize\StructArmed\Rule\Rules\Class_\AnonymousClassMayNotHaveEmptyParenthesesRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Tests\Support\TemporaryDirectoryCleanupTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;
use function file_get_contents;
use function file_put_contents;
use function mkdir;

#[CoversClass(AnonymousClassMayNotHaveEmptyParenthesesRule::class)]
#[CoversClass(AnonymousClassNode::class)]
#[CoversClass(AbstractPhpParserFixableRule::class)]
#[CoversClass(PhpParserFixerProcessor::class)]
#[CoversClass(RemoveAnonymousClassParenthesesVisitor::class)]
final class AnonymousClassMayNotHaveEmptyParenthesesRuleTest extends TestCase
{
    use TemporaryDirectoryCleanupTrait;

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $anonymousClassMayNotHaveEmptyParenthesesRule = new AnonymousClassMayNotHaveEmptyParenthesesRule('Source');

        $this->assertTrue($anonymousClassMayNotHaveEmptyParenthesesRule->appliesTo(
            $this->makeNode(layer: 'Source')
        ));
        $this->assertTrue($anonymousClassMayNotHaveEmptyParenthesesRule->appliesTo(
            $this->makeNode(layer: 'Other', layers: ['Other', 'Source'])
        ));
        $this->assertFalse($anonymousClassMayNotHaveEmptyParenthesesRule->appliesTo(
            $this->makeNode(layer: 'Other')
        ));
        $this->assertFalse($anonymousClassMayNotHaveEmptyParenthesesRule->appliesTo(
            $this->makeNode(layer: null)
        ));
    }

    public function testPassesAnonymousClassWithoutEmptyParentheses(): void
    {
        $anonymousClassMayNotHaveEmptyParenthesesRule = new AnonymousClassMayNotHaveEmptyParenthesesRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $anonymousClassMayNotHaveEmptyParenthesesRule->evaluate($this->makeNode(hasEmptyParentheses: false))
        );
    }

    public function testFlagsAnonymousClassWithEmptyParentheses(): void
    {
        $anonymousClassMayNotHaveEmptyParenthesesRule = new AnonymousClassMayNotHaveEmptyParenthesesRule('Source');

        $violation = $anonymousClassMayNotHaveEmptyParenthesesRule->evaluate(
            $this->makeNode(enclosingClassName: 'App\Factory')
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Anonymous class in [App\Factory] may not have empty parentheses after `class`',
            $violation->message
        );
        $this->assertSame('/src/Factory.php', $violation->file);
        $this->assertSame(7, $violation->line);
        $this->assertSame('App\Factory', $violation->className);
        $this->assertSame('Source', $violation->layer);
    }

    public function testReportsFileScopeForTopLevelAnonymousClass(): void
    {
        $anonymousClassMayNotHaveEmptyParenthesesRule = new AnonymousClassMayNotHaveEmptyParenthesesRule('Source');

        $violation = $anonymousClassMayNotHaveEmptyParenthesesRule->evaluate($this->makeNode());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(AnonymousClassNode::FILE_SCOPE, $violation->className);
    }

    public function testAnalyseThenFixRemovesOnlyEmptyParentheses(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-anonymous-class-parentheses');
        mkdir($basePath . '/src');

        $file = $basePath . '/src/Factory.php';

        file_put_contents($file, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            final class Factory
            {
                public function make(): object
                {
                    $a = new class () extends Base implements Foo {
                        /** doc */
                        public function bar(): int
                        {
                            return 1;
                        }

                        public function baz(): int
                        {
                            return 2;
                        }
                    };
                    $b = new class {};
                    $c = new readonly class(1) {};
                    $d = new #[Attr] class ( ) {};
                    $e = new class(){};
                    $f = [new class () {}, new class {}, new class ( ) {}];
                    $g = function () { return new class () {}; };

                    return $a;
                }
            }

            PHP);

        $architecture = Architecture::define()
            ->layer('Source', 'src/')
            ->rule('source.anonymous_classes', new AnonymousClassMayNotHaveEmptyParenthesesRule(layer: 'Source'));

        $violations = (new Analyser($basePath))
            ->analyse($architecture, [], null, AnalyserOptions::sequential())
            ->forRule('source.anonymous_classes');

        $this->assertSame(
            [11, 25, 26, 27, 27, 28],
            array_map(static fn (RuleViolation $ruleViolation): int => $ruleViolation->line, $violations)
        );
        $this->assertTrue($violations[0]->fixable);
        $this->assertSame('App\Factory', $violations[0]->className);

        $rule = $architecture->getRules()['source.anonymous_classes'];
        $this->assertInstanceOf(AnonymousClassMayNotHaveEmptyParenthesesRule::class, $rule);

        // The CLI fixes one file's violations in a single parse-and-write cycle.
        $this->assertTrue($rule->fix($violations[0], ...array_slice($violations, 1)));

        // Only the empty parentheses are gone: the class bodies, the brace
        // placement, and the blank lines are untouched.
        $this->assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            final class Factory
            {
                public function make(): object
                {
                    $a = new class extends Base implements Foo {
                        /** doc */
                        public function bar(): int
                        {
                            return 1;
                        }

                        public function baz(): int
                        {
                            return 2;
                        }
                    };
                    $b = new class {};
                    $c = new readonly class(1) {};
                    $d = new #[Attr] class {};
                    $e = new class {};
                    $f = [new class {}, new class {}, new class {}];
                    $g = function () { return new class {}; };

                    return $a;
                }
            }

            PHP, file_get_contents($file));

        // A second analysis of the fixed file is clean, and there is nothing left to fix.
        $this->assertCount(
            0,
            (new Analyser($basePath))
                ->analyse($architecture, [], null, AnalyserOptions::sequential())
                ->forRule('source.anonymous_classes')
        );
        $this->assertFalse($rule->fix($violations[0]));
    }

    public function testFixLeavesAnonymousClassOnAnotherLineAlone(): void
    {
        $basePath = $this->makeTemporaryDirectory('structarmed-anonymous-class-parentheses-line');
        $file     = $basePath . '/Factory.php';

        file_put_contents($file, <<<'PHP'
            <?php

            $a = new class () {};
            $b = new class () {};

            PHP);

        $anonymousClassMayNotHaveEmptyParenthesesRule = new AnonymousClassMayNotHaveEmptyParenthesesRule('Source');

        $this->assertTrue(
            $anonymousClassMayNotHaveEmptyParenthesesRule->fix(new RuleViolation('message', $file, 4, 'file scope'))
        );

        $this->assertSame(<<<'PHP'
            <?php

            $a = new class () {};
            $b = new class {};

            PHP, file_get_contents($file));
    }

    /** @param list<string> $layers */
    private function makeNode(
        ?string $layer = 'Source',
        ?string $enclosingClassName = null,
        bool $hasEmptyParentheses = true,
        array $layers = [],
    ): AnonymousClassNode {
        return new AnonymousClassNode(
            file:                '/src/Factory.php',
            line:                7,
            extends:             null,
            layer:               $layer,
            enclosingClassName:  $enclosingClassName,
            hasEmptyParentheses: $hasEmptyParentheses,
            layers:              $layers,
        );
    }
}
