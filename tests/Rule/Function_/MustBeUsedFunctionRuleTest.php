<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Function_;

use Boundwize\StructArmed\Analyser\FunctionNode;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Function_\RemoveFunctionVisitor;
use Boundwize\StructArmed\Rule\Rules\Function_\MustBeUsedFunctionRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\UsedFunctionAwareRuleInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(MustBeUsedFunctionRule::class)]
final class MustBeUsedFunctionRuleTest extends TestCase
{
    private function makeNode(string $layer = 'Domain', bool $isReferenced = false): FunctionNode
    {
        return new FunctionNode(
            functionName: 'App\\Domain\\format_money',
            file:         '/src/Domain/functions.php',
            line:         3,
            layer:        $layer,
            isReferenced: $isReferenced,
        );
    }

    public function testPassesWhenFunctionIsReferenced(): void
    {
        $mustBeUsedFunctionRule = new MustBeUsedFunctionRule(layer: 'Domain');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustBeUsedFunctionRule->evaluate($this->makeNode(isReferenced: true))
        );
    }

    public function testViolatesWhenFunctionIsNotReferenced(): void
    {
        $mustBeUsedFunctionRule = new MustBeUsedFunctionRule(layer: 'Domain');

        $violation = $mustBeUsedFunctionRule->evaluate($this->makeNode());

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Function [App\\Domain\\format_money()] must be called or referenced as a callable',
            $violation->message
        );
        $this->assertSame('App\\Domain\\format_money', $violation->functionName);
        $this->assertSame(3, $violation->line);
    }

    public function testIsUsedFunctionAware(): void
    {
        $this->assertInstanceOf(
            UsedFunctionAwareRuleInterface::class,
            new MustBeUsedFunctionRule(layer: 'Domain')
        );
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustBeUsedFunctionRule(layer: 'Domain'));
    }

    public function testCreatesRemoveFunctionFixerVisitor(): void
    {
        $mustBeUsedFunctionRule = new MustBeUsedFunctionRule(layer: 'Domain');
        $reflectionMethod       = new ReflectionMethod($mustBeUsedFunctionRule, 'createFixerVisitor');
        $removeFunctionVisitor  = $reflectionMethod->invoke(
            $mustBeUsedFunctionRule,
            $mustBeUsedFunctionRule->evaluate($this->makeNode())
        );

        $this->assertInstanceOf(RemoveFunctionVisitor::class, $removeFunctionVisitor);
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mustBeUsedFunctionRule = new MustBeUsedFunctionRule(layer: 'Domain');

        $this->assertTrue($mustBeUsedFunctionRule->appliesTo($this->makeNode()));
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mustBeUsedFunctionRule = new MustBeUsedFunctionRule(layer: 'Domain');

        $this->assertFalse($mustBeUsedFunctionRule->appliesTo($this->makeNode(layer: 'Infrastructure')));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $mustBeUsedFunctionRule = new MustBeUsedFunctionRule(layer: 'Domain', functionNamePattern: '/_money$/');

        $this->assertTrue($mustBeUsedFunctionRule->appliesTo($this->makeNode()));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $mustBeUsedFunctionRule = new MustBeUsedFunctionRule(layer: 'Domain', functionNamePattern: '/^helper/');

        $this->assertFalse($mustBeUsedFunctionRule->appliesTo($this->makeNode()));
    }
}
