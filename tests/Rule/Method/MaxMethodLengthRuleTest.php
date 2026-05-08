<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Method\MaxMethodLengthRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxMethodLengthRule::class)]
final class MaxMethodLengthRuleTest extends TestCase
{
    private function makeNode(int $lineCount, string $layer = 'Controller'): ClassNode
    {
        return new ClassNode(
            className:   'App\\Controller\\OrderController',
            file:        '/fake.php',
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     true,
            isInterface: false,
            isReadonly:  false,
            methods: [
                new MethodNode(
                    name:                 'store',
                    visibility:           'public',
                    hasReturnType:        true,
                    isStatic:             false,
                    paramCount:           1,
                    cyclomaticComplexity: 2,
                    lineCount:            $lineCount,
                ),
            ],
        );
    }

    private function makeNodeWithMethods(MethodNode ...$methods): ClassNode
    {
        return new ClassNode(
            className:   'App\\Controller\\OrderController',
            file:        '/fake.php',
            line:        1,
            layer:       'Controller',
            extends:     null,
            isAbstract:  false,
            isFinal:     true,
            isInterface: false,
            isReadonly:  false,
            methods:     $methods,
        );
    }

    public function testPassesWhenMethodUnderLimit(): void
    {
        $maxMethodLengthRule = new MaxMethodLengthRule(layer: 'Controller', maxLines: 20);
        $classNode           = $this->makeNode(lineCount: 10);

        $this->assertNotInstanceOf(RuleViolation::class, $maxMethodLengthRule->evaluate($classNode));
    }

    public function testPassesWhenMethodAtLimit(): void
    {
        $maxMethodLengthRule = new MaxMethodLengthRule(layer: 'Controller', maxLines: 20);
        $classNode           = $this->makeNode(lineCount: 20);

        $this->assertNotInstanceOf(RuleViolation::class, $maxMethodLengthRule->evaluate($classNode));
    }

    public function testViolatesWhenMethodOverLimit(): void
    {
        $maxMethodLengthRule = new MaxMethodLengthRule(layer: 'Controller', maxLines: 20);
        $classNode           = $this->makeNode(lineCount: 35);

        $violation = $maxMethodLengthRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('35', $violation->message);
        $this->assertStringContainsString('20', $violation->message);
    }

    public function testReportsAllMethodsOverLimit(): void
    {
        $maxMethodLengthRule = new MaxMethodLengthRule(layer: 'Controller', maxLines: 20);
        $classNode           = $this->makeNodeWithMethods(
            new MethodNode('store', 'public', true, false, 1, 2, 35, 11),
            new MethodNode('update', 'public', true, false, 1, 2, 28, 47),
            new MethodNode('show', 'public', true, false, 1, 2, 12, 83),
        );

        $violations = $maxMethodLengthRule->evaluateAll($classNode);

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('store', $violations[0]->message);
        $this->assertSame(11, $violations[0]->line);
        $this->assertStringContainsString('update', $violations[1]->message);
        $this->assertSame(47, $violations[1]->line);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $maxMethodLengthRule = new MaxMethodLengthRule(layer: 'Controller', maxLines: 5);
        $classNode           = $this->makeNode(lineCount: 100, layer: 'Domain');

        $this->assertFalse($maxMethodLengthRule->appliesTo($classNode));
    }
}
