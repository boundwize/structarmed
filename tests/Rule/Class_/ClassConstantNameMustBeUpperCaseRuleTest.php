<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ConstantNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassConstantNameMustBeUpperCaseRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstantNode::class)]
#[CoversClass(ClassConstantNameMustBeUpperCaseRule::class)]
final class ClassConstantNameMustBeUpperCaseRuleTest extends TestCase
{
    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $classConstantNameMustBeUpperCaseRule = new ClassConstantNameMustBeUpperCaseRule('Source');

        $this->assertTrue($classConstantNameMustBeUpperCaseRule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($classConstantNameMustBeUpperCaseRule->appliesTo($this->makeNode([], 'Other')));
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $classConstantNameMustBeUpperCaseRule = new ClassConstantNameMustBeUpperCaseRule('Source');

        $violation = $classConstantNameMustBeUpperCaseRule->evaluate($this->makeNode([new ConstantNode('badName')]));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(1, $violation->line);
    }

    public function testPassesUpperCaseConstants(): void
    {
        $classConstantNameMustBeUpperCaseRule = new ClassConstantNameMustBeUpperCaseRule('Source');

        $this->assertSame(
            [],
            $classConstantNameMustBeUpperCaseRule->evaluateAll(
                $this->makeNode([new ConstantNode('DATE_APPROVED')])
            )
        );
    }

    public function testViolatesNonUpperCaseConstants(): void
    {
        $classConstantNameMustBeUpperCaseRule = new ClassConstantNameMustBeUpperCaseRule('Source');

        $violations = $classConstantNameMustBeUpperCaseRule->evaluateAll(
            $this->makeNode([new ConstantNode('dateApproved', 7)])
        );

        $this->assertCount(1, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertSame(7, $violations[0]->line);
    }

    /**
     * @param list<ConstantNode> $constants
     */
    private function makeNode(array $constants, string $layer = 'Source'): ClassNode
    {
        return new ClassNode(
            className: 'App\\Order',
            file: '/fake.php',
            line: 1,
            layer: $layer,
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: false,
            isReadonly: false,
            constants: $constants,
        );
    }
}
