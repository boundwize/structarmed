<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Method\MaxCyclomaticComplexityRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxCyclomaticComplexityRule::class)]
final class MaxCyclomaticComplexityRuleTest extends TestCase
{
    private function makeNode(int $complexity, string $layer = 'Controller'): ClassNode
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
                    name:                 'handle',
                    visibility:           'public',
                    hasReturnType:        true,
                    isStatic:             false,
                    paramCount:           1,
                    cyclomaticComplexity: $complexity,
                    lineCount:            10,
                ),
            ],
        );
    }

    public function testPassesWhenComplexityUnderLimit(): void
    {
        $rule = new MaxCyclomaticComplexityRule(layer: 'Controller', maxComplexity: 5);
        $node = $this->makeNode(complexity: 3);

        $this->assertNull($rule->evaluate($node));
    }

    public function testPassesWhenComplexityAtLimit(): void
    {
        $rule = new MaxCyclomaticComplexityRule(layer: 'Controller', maxComplexity: 5);
        $node = $this->makeNode(complexity: 5);

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenComplexityOverLimit(): void
    {
        $rule = new MaxCyclomaticComplexityRule(layer: 'Controller', maxComplexity: 5);
        $node = $this->makeNode(complexity: 8);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('8', $violation->message);
        $this->assertStringContainsString('5', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $rule = new MaxCyclomaticComplexityRule(layer: 'Controller', maxComplexity: 5);
        $node = $this->makeNode(complexity: 100, layer: 'Domain');

        $this->assertFalse($rule->appliesTo($node));
    }
}
