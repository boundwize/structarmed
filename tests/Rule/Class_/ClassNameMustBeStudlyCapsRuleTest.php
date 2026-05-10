<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustBeStudlyCapsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassNameMustBeStudlyCapsRule::class)]
final class ClassNameMustBeStudlyCapsRuleTest extends TestCase
{
    public function testPassesStudlyCapsClassName(): void
    {
        $classNameMustBeStudlyCapsRule = new ClassNameMustBeStudlyCapsRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $classNameMustBeStudlyCapsRule->evaluate($this->makeNode('App\\OrderService'))
        );
    }

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $classNameMustBeStudlyCapsRule = new ClassNameMustBeStudlyCapsRule('Source');

        $this->assertTrue($classNameMustBeStudlyCapsRule->appliesTo($this->makeNode('App\\OrderService')));
        $this->assertFalse($classNameMustBeStudlyCapsRule->appliesTo($this->makeNode('App\\OrderService', 'Other')));
    }

    public function testViolatesNonStudlyCapsClassName(): void
    {
        $classNameMustBeStudlyCapsRule = new ClassNameMustBeStudlyCapsRule('Source');

        $this->assertInstanceOf(
            RuleViolation::class,
            $classNameMustBeStudlyCapsRule->evaluate($this->makeNode('App\\order_service'))
        );
    }

    private function makeNode(string $className, string $layer = 'Source'): ClassNode
    {
        return new ClassNode(
            className: $className,
            file: '/fake.php',
            line: 1,
            layer: $layer,
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: false,
            isReadonly: false,
        );
    }
}
