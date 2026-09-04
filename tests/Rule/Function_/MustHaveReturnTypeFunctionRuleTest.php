<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Function_;

use Boundwize\StructArmed\Analyser\FunctionNode;
use Boundwize\StructArmed\Rule\Rules\Function_\MustHaveReturnTypeFunctionRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MustHaveReturnTypeFunctionRule::class)]
final class MustHaveReturnTypeFunctionRuleTest extends TestCase
{
    private function makeNode(
        string $functionName = 'App\\Helper\\format_price',
        bool $hasReturnType = false,
        ?string $layer = 'Helper',
    ): FunctionNode {
        return new FunctionNode(
            functionName:  $functionName,
            file:          '/src/Helper/functions.php',
            line:          10,
            layer:         $layer,
            hasReturnType: $hasReturnType,
        );
    }

    public function testPassesWhenFunctionHasReturnType(): void
    {
        $mustHaveReturnTypeFunctionRule = new MustHaveReturnTypeFunctionRule(layer: 'Helper');
        $functionNode                   = $this->makeNode(hasReturnType: true);

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustHaveReturnTypeFunctionRule->evaluate($functionNode)
        );
    }

    public function testViolatesWhenFunctionMissingReturnType(): void
    {
        $mustHaveReturnTypeFunctionRule = new MustHaveReturnTypeFunctionRule(layer: 'Helper');
        $functionNode                   = $this->makeNode(hasReturnType: false);

        $violation = $mustHaveReturnTypeFunctionRule->evaluate($functionNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('App\\Helper\\format_price()', $violation->message);
        $this->assertSame('App\\Helper\\format_price', $violation->functionName);
        $this->assertSame('Helper', $violation->layer);
    }

    public function testAppliesToMatchingLayer(): void
    {
        $mustHaveReturnTypeFunctionRule = new MustHaveReturnTypeFunctionRule(layer: 'Helper');

        $this->assertTrue($mustHaveReturnTypeFunctionRule->appliesTo($this->makeNode()));
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mustHaveReturnTypeFunctionRule = new MustHaveReturnTypeFunctionRule(layer: 'Helper');
        $functionNode                   = $this->makeNode(layer: 'Controller');

        $this->assertFalse($mustHaveReturnTypeFunctionRule->appliesTo($functionNode));
    }

    public function testSingleRuleInstanceReportsOneViolationPerFunction(): void
    {
        // One FunctionRuleInterface instance is evaluated once per function
        // node, so multiple functions yield multiple independent violations.
        $mustHaveReturnTypeFunctionRule = new MustHaveReturnTypeFunctionRule(layer: 'Helper');

        $firstViolation  = $mustHaveReturnTypeFunctionRule->evaluate(
            $this->makeNode(functionName: 'App\\Helper\\format_price')
        );
        $secondViolation = $mustHaveReturnTypeFunctionRule->evaluate(
            $this->makeNode(functionName: 'App\\Helper\\format_date')
        );

        $this->assertInstanceOf(RuleViolation::class, $firstViolation);
        $this->assertInstanceOf(RuleViolation::class, $secondViolation);
        $this->assertStringContainsString('format_price', $firstViolation->message);
        $this->assertStringContainsString('format_date', $secondViolation->message);
    }
}
