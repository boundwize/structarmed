<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustNotHavePrefixRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassNameMustNotHavePrefixRule::class)]
final class ClassNameMustNotHavePrefixRuleTest extends TestCase
{
    public function testPassesWhenClassNameDoesNotHavePrefix(): void
    {
        $classNameMustNotHavePrefixRule = new ClassNameMustNotHavePrefixRule(
            layer: 'Model',
            prefix: 'Model'
        );

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $classNameMustNotHavePrefixRule->evaluate($this->makeNode('App\\Model\\Order'))
        );
    }

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $classNameMustNotHavePrefixRule = new ClassNameMustNotHavePrefixRule(
            layer: 'Model',
            prefix: 'Model'
        );

        $this->assertTrue($classNameMustNotHavePrefixRule->appliesTo($this->makeNode('App\\Model\\Order')));
        $this->assertFalse($classNameMustNotHavePrefixRule->appliesTo($this->makeNode(
            'App\\Service\\ModelOrder',
            'Service'
        )));
    }

    public function testViolatesWhenClassNameHasPrefix(): void
    {
        $classNameMustNotHavePrefixRule = new ClassNameMustNotHavePrefixRule(
            layer: 'Model',
            prefix: 'Model'
        );

        $violation = $classNameMustNotHavePrefixRule->evaluate($this->makeNode('App\\Model\\ModelOrder'));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('Model', $violation->message);
    }

    private function makeNode(string $className, string $layer = 'Model'): ClassNode
    {
        return new ClassNode(
            className:   $className,
            file:        '/fake.php',
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     false,
            isInterface: false,
            isReadonly:  false,
        );
    }
}
