<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\PropertyNode;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclarePropertyVisibilityRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MustDeclarePropertyVisibilityRule::class)]
#[CoversClass(PropertyNode::class)]
final class MustDeclarePropertyVisibilityRuleTest extends TestCase
{
    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $rule = new MustDeclarePropertyVisibilityRule('Source');

        $this->assertTrue($rule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($rule->appliesTo($this->makeNode([], 'Other')));
    }

    public function testPassesWhenVisibilityIsExplicit(): void
    {
        $rule = new MustDeclarePropertyVisibilityRule('Source');

        $this->assertSame(
            [],
            $rule->evaluateAll($this->makeNode([
                new PropertyNode(name: 'status', visibility: 'private', hasExplicitVisibility: true),
            ]))
        );
    }

    public function testViolatesWhenVisibilityIsImplicit(): void
    {
        $rule = new MustDeclarePropertyVisibilityRule('Source');

        $violations = $rule->evaluateAll($this->makeNode([
            new PropertyNode(name: 'status', visibility: 'public', hasExplicitVisibility: false, line: 7),
        ]));

        $this->assertCount(1, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertStringContainsString('$status', $violations[0]->message);
        $this->assertSame(7, $violations[0]->line);
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $rule = new MustDeclarePropertyVisibilityRule('Source');

        $violation = $rule->evaluate($this->makeNode([
            new PropertyNode(name: 'status', visibility: 'public', hasExplicitVisibility: false),
        ]));

        $this->assertInstanceOf(RuleViolation::class, $violation);
    }

    /**
     * @param list<PropertyNode> $properties
     */
    private function makeNode(array $properties, string $layer = 'Source'): ClassNode
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
            properties: $properties,
        );
    }
}
