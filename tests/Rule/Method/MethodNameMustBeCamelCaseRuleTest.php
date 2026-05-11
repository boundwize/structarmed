<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Method\MethodNameMustBeCamelCaseRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MethodNameMustBeCamelCaseRule::class)]
final class MethodNameMustBeCamelCaseRuleTest extends TestCase
{
    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $methodNameMustBeCamelCaseRule = new MethodNameMustBeCamelCaseRule('Source');

        $this->assertTrue($methodNameMustBeCamelCaseRule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($methodNameMustBeCamelCaseRule->appliesTo($this->makeNode([], 'Other')));
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $methodNameMustBeCamelCaseRule = new MethodNameMustBeCamelCaseRule('Source');

        $violation = $methodNameMustBeCamelCaseRule->evaluate($this->makeNode([
            new MethodNode('Bad_name', 'public', true, false, 0, 1, 1),
        ]));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(1, $violation->line);
    }

    public function testPassesCamelCaseMethodsAndIgnoresMagicMethods(): void
    {
        $methodNameMustBeCamelCaseRule = new MethodNameMustBeCamelCaseRule('Source');

        $this->assertSame([], $methodNameMustBeCamelCaseRule->evaluateAll($this->makeNode([
            new MethodNode('shipOrder', 'public', true, false, 0, 1, 1),
            new MethodNode('__construct', 'public', false, false, 0, 1, 1),
        ])));
    }

    public function testViolatesNonCamelCaseMethods(): void
    {
        $methodNameMustBeCamelCaseRule = new MethodNameMustBeCamelCaseRule('Source');

        $violations = $methodNameMustBeCamelCaseRule->evaluateAll($this->makeNode([
            new MethodNode('Ship_Order', 'public', true, false, 0, 1, 1, line: 9),
        ]));

        $this->assertCount(1, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertSame(9, $violations[0]->line);
    }

    /**
     * @param list<MethodNode> $methods
     */
    private function makeNode(array $methods, string $layer = 'Source'): ClassNode
    {
        return new ClassNode(
            className: 'App\\Order',
            file: '/fake.php',
            line: 1,
            layer: $layer,
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: false,
            isReadonly: false,
            methods: $methods,
        );
    }
}
