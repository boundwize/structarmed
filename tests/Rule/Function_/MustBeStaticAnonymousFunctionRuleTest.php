<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Function_;

use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike\AddStaticAnonymousFunctionVisitor;
use Boundwize\StructArmed\Rule\Rules\Function_\MustBeStaticAnonymousFunctionRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(MustBeStaticAnonymousFunctionRule::class)]
final class MustBeStaticAnonymousFunctionRuleTest extends TestCase
{
    private function makeNode(
        bool $isStatic = false,
        bool $usesThis = false,
        bool $isArrowFunction = false,
        ?string $layer = 'Domain',
        ?string $enclosingClassName = 'App\\Domain\\Handler',
    ): AnonymousFunctionNode {
        return new AnonymousFunctionNode(
            file:               '/src/Domain/Handler.php',
            line:               12,
            layer:              $layer,
            isArrowFunction:    $isArrowFunction,
            isStatic:           $isStatic,
            enclosingClassName: $enclosingClassName,
            usesThis:           $usesThis,
        );
    }

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $mustBeStaticAnonymousFunctionRule = new MustBeStaticAnonymousFunctionRule(layer: 'Domain');

        $this->assertTrue($mustBeStaticAnonymousFunctionRule->appliesTo($this->makeNode()));
        $this->assertFalse(
            $mustBeStaticAnonymousFunctionRule->appliesTo($this->makeNode(layer: 'Infrastructure'))
        );
        $this->assertFalse(
            $mustBeStaticAnonymousFunctionRule->appliesTo($this->makeNode(layer: null))
        );
    }

    public function testPassesWhenAlreadyStatic(): void
    {
        $mustBeStaticAnonymousFunctionRule = new MustBeStaticAnonymousFunctionRule(layer: 'Domain');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustBeStaticAnonymousFunctionRule->evaluate($this->makeNode(isStatic: true))
        );
    }

    public function testPassesWhenClosureUsesThis(): void
    {
        $mustBeStaticAnonymousFunctionRule = new MustBeStaticAnonymousFunctionRule(layer: 'Domain');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustBeStaticAnonymousFunctionRule->evaluate($this->makeNode(usesThis: true))
        );
    }

    public function testViolatesForNonStaticClosure(): void
    {
        $mustBeStaticAnonymousFunctionRule = new MustBeStaticAnonymousFunctionRule(layer: 'Domain');
        $violation                         = $mustBeStaticAnonymousFunctionRule->evaluate(
            $this->makeNode()
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Closure in [App\\Domain\\Handler] must be declared static', $violation->message);
        $this->assertSame('/src/Domain/Handler.php', $violation->file);
        $this->assertSame(12, $violation->line);
        $this->assertSame('App\\Domain\\Handler', $violation->className);
        $this->assertSame('Domain', $violation->layer);
    }

    public function testViolatesForNonStaticArrowFunctionAtFileScope(): void
    {
        $mustBeStaticAnonymousFunctionRule = new MustBeStaticAnonymousFunctionRule(layer: 'Domain');
        $violation                         = $mustBeStaticAnonymousFunctionRule->evaluate(
            $this->makeNode(isArrowFunction: true, enclosingClassName: null)
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Arrow function in [file scope] must be declared static', $violation->message);
        $this->assertSame('file scope', $violation->className);
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustBeStaticAnonymousFunctionRule(layer: 'Domain'));
    }

    public function testCreatesStaticAnonymousFunctionFixerVisitor(): void
    {
        $mustBeStaticAnonymousFunctionRule = new MustBeStaticAnonymousFunctionRule(layer: 'Domain');
        $reflectionMethod                  = new ReflectionMethod(
            $mustBeStaticAnonymousFunctionRule,
            'createFixerVisitor'
        );
        $visitor                           = $reflectionMethod->invoke(
            $mustBeStaticAnonymousFunctionRule,
            new RuleViolation(
                message:   'Closure in [App\\Domain\\Handler] must be declared static',
                file:      '/src/Domain/Handler.php',
                line:      12,
                className: 'App\\Domain\\Handler',
                layer:     'Domain',
            )
        );

        $this->assertInstanceOf(AddStaticAnonymousFunctionVisitor::class, $visitor);
    }
}
