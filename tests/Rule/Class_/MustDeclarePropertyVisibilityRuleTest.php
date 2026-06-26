<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\PropertyNode;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Property\AddPublicPropertyVisibilityVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclarePropertyVisibilityRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(MustDeclarePropertyVisibilityRule::class)]
#[CoversClass(PropertyNode::class)]
#[CoversClass(AddPublicPropertyVisibilityVisitor::class)]
final class MustDeclarePropertyVisibilityRuleTest extends TestCase
{
    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $mustDeclarePropertyVisibilityRule = new MustDeclarePropertyVisibilityRule('Source');

        $this->assertTrue($mustDeclarePropertyVisibilityRule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($mustDeclarePropertyVisibilityRule->appliesTo($this->makeNode([], 'Other')));
    }

    public function testPassesWhenVisibilityIsExplicit(): void
    {
        $mustDeclarePropertyVisibilityRule = new MustDeclarePropertyVisibilityRule('Source');

        $this->assertSame(
            [],
            $mustDeclarePropertyVisibilityRule->evaluateAll($this->makeNode([
                new PropertyNode(name: 'status', visibility: 'private', hasExplicitVisibility: true),
            ]))
        );
    }

    public function testViolatesWhenVisibilityIsImplicit(): void
    {
        $mustDeclarePropertyVisibilityRule = new MustDeclarePropertyVisibilityRule('Source');

        $violations = $mustDeclarePropertyVisibilityRule->evaluateAll($this->makeNode([
            new PropertyNode(name: 'status', visibility: 'public', hasExplicitVisibility: false, line: 7),
        ]));

        $this->assertCount(1, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertStringContainsString('$status', $violations[0]->message);
        $this->assertSame(7, $violations[0]->line);
        $this->assertSame('status', $violations[0]->propertyName);
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $mustDeclarePropertyVisibilityRule = new MustDeclarePropertyVisibilityRule('Source');

        $violation = $mustDeclarePropertyVisibilityRule->evaluate($this->makeNode([
            new PropertyNode(name: 'status', visibility: 'public', hasExplicitVisibility: false),
        ]));

        $this->assertInstanceOf(RuleViolation::class, $violation);
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustDeclarePropertyVisibilityRule('Source'));
    }

    public function testCreatesPropertyVisibilityFixerVisitor(): void
    {
        $mustDeclarePropertyVisibilityRule = new MustDeclarePropertyVisibilityRule('Source');
        $reflectionMethod                  = new ReflectionMethod(
            $mustDeclarePropertyVisibilityRule,
            'createFixerVisitor'
        );

        $visitor = $reflectionMethod->invoke(
            $mustDeclarePropertyVisibilityRule,
            new RuleViolation(
                message:      'Property [Order::$status] must declare an explicit visibility',
                file:         '/src/Order.php',
                line:         7,
                className:    'Order',
                propertyName: 'status',
            )
        );

        $this->assertInstanceOf(AddPublicPropertyVisibilityVisitor::class, $visitor);
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
