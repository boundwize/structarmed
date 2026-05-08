<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Method\MaxCyclomaticComplexityRule;
use Boundwize\StructArmed\Rule\RuleViolation;
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
        $maxCyclomaticComplexityRule = new MaxCyclomaticComplexityRule(layer: 'Controller', maxComplexity: 5);
        $classNode                   = $this->makeNode(complexity: 3);

        $this->assertNotInstanceOf(RuleViolation::class, $maxCyclomaticComplexityRule->evaluate($classNode));
    }

    public function testPassesWhenComplexityAtLimit(): void
    {
        $maxCyclomaticComplexityRule = new MaxCyclomaticComplexityRule(layer: 'Controller', maxComplexity: 5);
        $classNode                   = $this->makeNode(complexity: 5);

        $this->assertNotInstanceOf(RuleViolation::class, $maxCyclomaticComplexityRule->evaluate($classNode));
    }

    public function testViolatesWhenComplexityOverLimit(): void
    {
        $maxCyclomaticComplexityRule = new MaxCyclomaticComplexityRule(layer: 'Controller', maxComplexity: 5);
        $classNode                   = $this->makeNode(complexity: 8);

        $violation = $maxCyclomaticComplexityRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('8', $violation->message);
        $this->assertStringContainsString('5', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $maxCyclomaticComplexityRule = new MaxCyclomaticComplexityRule(layer: 'Controller', maxComplexity: 5);
        $classNode                   = $this->makeNode(complexity: 100, layer: 'Domain');

        $this->assertFalse($maxCyclomaticComplexityRule->appliesTo($classNode));
    }
}
