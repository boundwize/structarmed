<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ConstantNode;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassConst\AddPublicConstantVisibilityVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustDeclareConstantVisibilityRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(MustDeclareConstantVisibilityRule::class)]
#[CoversClass(AddPublicConstantVisibilityVisitor::class)]
final class MustDeclareConstantVisibilityRuleTest extends TestCase
{
    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $mustDeclareConstantVisibilityRule = new MustDeclareConstantVisibilityRule('Source');

        $this->assertTrue($mustDeclareConstantVisibilityRule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($mustDeclareConstantVisibilityRule->appliesTo($this->makeNode([], 'Other')));
    }

    public function testPassesWhenVisibilityIsExplicit(): void
    {
        $mustDeclareConstantVisibilityRule = new MustDeclareConstantVisibilityRule('Source');

        $this->assertSame(
            [],
            $mustDeclareConstantVisibilityRule->evaluateAll($this->makeNode([
                new ConstantNode(name: 'VERSION', visibility: 'public', hasExplicitVisibility: true),
            ]))
        );
    }

    public function testViolatesWhenVisibilityIsImplicit(): void
    {
        $mustDeclareConstantVisibilityRule = new MustDeclareConstantVisibilityRule('Source');

        $violations = $mustDeclareConstantVisibilityRule->evaluateAll($this->makeNode([
            new ConstantNode(name: 'VERSION', visibility: 'public', hasExplicitVisibility: false, line: 5),
        ]));

        $this->assertCount(1, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertStringContainsString('VERSION', $violations[0]->message);
        $this->assertSame(5, $violations[0]->line);
        $this->assertSame('VERSION', $violations[0]->constantName);
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $mustDeclareConstantVisibilityRule = new MustDeclareConstantVisibilityRule('Source');

        $violation = $mustDeclareConstantVisibilityRule->evaluate($this->makeNode([
            new ConstantNode(name: 'VERSION', hasExplicitVisibility: false),
        ]));

        $this->assertInstanceOf(RuleViolation::class, $violation);
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustDeclareConstantVisibilityRule('Source'));
    }

    public function testCreatesConstantVisibilityFixerVisitor(): void
    {
        $mustDeclareConstantVisibilityRule = new MustDeclareConstantVisibilityRule('Source');
        $reflectionMethod                  = new ReflectionMethod(
            $mustDeclareConstantVisibilityRule,
            'createFixerVisitor'
        );

        $visitor = $reflectionMethod->invoke(
            $mustDeclareConstantVisibilityRule,
            new RuleViolation(
                message:      'Constant [Order::VERSION] must declare an explicit visibility',
                file:         '/src/Order.php',
                line:         5,
                className:    'Order',
                constantName: 'VERSION',
            )
        );

        $this->assertInstanceOf(AddPublicConstantVisibilityVisitor::class, $visitor);
    }

    /**
     * @param list<ConstantNode> $constants
     */
    private function makeNode(array $constants, string $layer = 'Source'): ClassNode
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
            constants:  $constants,
        );
    }
}
