<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Method\MaxMethodLengthRule;
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

    public function testPassesWhenMethodUnderLimit(): void
    {
        $rule = new MaxMethodLengthRule(layer: 'Controller', maxLines: 20);
        $node = $this->makeNode(lineCount: 10);

        $this->assertNull($rule->evaluate($node));
    }

    public function testPassesWhenMethodAtLimit(): void
    {
        $rule = new MaxMethodLengthRule(layer: 'Controller', maxLines: 20);
        $node = $this->makeNode(lineCount: 20);

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenMethodOverLimit(): void
    {
        $rule = new MaxMethodLengthRule(layer: 'Controller', maxLines: 20);
        $node = $this->makeNode(lineCount: 35);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('35', $violation->message);
        $this->assertStringContainsString('20', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $rule = new MaxMethodLengthRule(layer: 'Controller', maxLines: 5);
        $node = $this->makeNode(lineCount: 100, layer: 'Domain');

        $this->assertFalse($rule->appliesTo($node));
    }
}
