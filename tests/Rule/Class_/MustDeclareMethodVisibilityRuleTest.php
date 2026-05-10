<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclareMethodVisibilityRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MustDeclareMethodVisibilityRule::class)]
final class MustDeclareMethodVisibilityRuleTest extends TestCase
{
    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $rule = new MustDeclareMethodVisibilityRule('Source');

        $this->assertTrue($rule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($rule->appliesTo($this->makeNode([], 'Other')));
    }

    public function testPassesWhenVisibilityIsExplicit(): void
    {
        $rule = new MustDeclareMethodVisibilityRule('Source');

        $this->assertSame(
            [],
            $rule->evaluateAll($this->makeNode([
                new MethodNode('save', 'public', true, false, 0, 1, 3, hasExplicitVisibility: true),
            ]))
        );
    }

    public function testViolatesWhenVisibilityIsImplicit(): void
    {
        $rule = new MustDeclareMethodVisibilityRule('Source');

        $violations = $rule->evaluateAll($this->makeNode([
            new MethodNode('save', 'public', true, false, 0, 1, 3, hasExplicitVisibility: false, line: 5),
        ]));

        $this->assertCount(1, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertStringContainsString('save', $violations[0]->message);
        $this->assertSame(5, $violations[0]->line);
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $rule = new MustDeclareMethodVisibilityRule('Source');

        $violation = $rule->evaluate($this->makeNode([
            new MethodNode('save', 'public', true, false, 0, 1, 3, hasExplicitVisibility: false),
        ]));

        $this->assertInstanceOf(RuleViolation::class, $violation);
    }

    /**
     * @param list<MethodNode> $methods
     */
    private function makeNode(array $methods, string $layer = 'Source'): ClassNode
    {
        return new ClassNode(
            className:  'App\\Order',
            file:       '/fake.php',
            line:       1,
            layer:      $layer,
            extends:    null,
            isAbstract: false,
            isFinal:    false,
            isInterface: false,
            isReadonly: false,
            methods:    $methods,
        );
    }
}
