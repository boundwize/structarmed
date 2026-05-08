<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Class_\MaxDependencyCountRule;
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
        $rule = new MaxDependencyCountRule(layer: 'Controller', maxCount: 5);
        $node = $this->makeNode(constructorParams: 3);

        $this->assertNull($rule->evaluate($node));
    }

    public function testPassesWhenAtLimit(): void
    {
        $rule = new MaxDependencyCountRule(layer: 'Controller', maxCount: 5);
        $node = $this->makeNode(constructorParams: 5);

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenOverLimit(): void
    {
        $rule = new MaxDependencyCountRule(layer: 'Controller', maxCount: 5);
        $node = $this->makeNode(constructorParams: 7);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('7', $violation->message);
        $this->assertStringContainsString('5', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $rule = new MaxDependencyCountRule(layer: 'Controller', maxCount: 5);
        $node = $this->makeNode(constructorParams: 10, layer: 'Domain');

        $this->assertFalse($rule->appliesTo($node));
    }
}
