<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Class_\MaxDependencyCountRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxDependencyCountRule::class)]
final class MaxDependencyCountRuleTest extends TestCase
{
    private function makeNode(int $constructorParams, string $layer = 'Controller'): ClassNode
    {
        $methods = [
            new MethodNode(
                name:                 '__construct',
                visibility:           'public',
                hasReturnType:        false,
                isStatic:             false,
                paramCount:           $constructorParams,
                cyclomaticComplexity: 1,
                lineCount:            $constructorParams + 2,
            ),
        ];

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
            methods:     $methods,
        );
    }

    public function testPassesWhenUnderLimit(): void
    {
        $maxDependencyCountRule = new MaxDependencyCountRule(layer: 'Controller', maxCount: 5);
        $classNode              = $this->makeNode(constructorParams: 3);

        $this->assertNotInstanceOf(RuleViolation::class, $maxDependencyCountRule->evaluate($classNode));
    }

    public function testPassesWhenAtLimit(): void
    {
        $maxDependencyCountRule = new MaxDependencyCountRule(layer: 'Controller', maxCount: 5);
        $classNode              = $this->makeNode(constructorParams: 5);

        $this->assertNotInstanceOf(RuleViolation::class, $maxDependencyCountRule->evaluate($classNode));
    }

    public function testViolatesWhenOverLimit(): void
    {
        $maxDependencyCountRule = new MaxDependencyCountRule(layer: 'Controller', maxCount: 5);
        $classNode              = $this->makeNode(constructorParams: 7);

        $violation = $maxDependencyCountRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('7', $violation->message);
        $this->assertStringContainsString('5', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $maxDependencyCountRule = new MaxDependencyCountRule(layer: 'Controller', maxCount: 5);
        $classNode              = $this->makeNode(constructorParams: 10, layer: 'Domain');

        $this->assertFalse($maxDependencyCountRule->appliesTo($classNode));
    }
}
