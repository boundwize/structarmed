<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustHaveSuffixRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassNameMustHaveSuffixRule::class)]
final class ClassNameMustHaveSuffixRuleTest extends TestCase
{
    public function testPassesWhenClassNameHasSuffix(): void
    {
        $classNameMustHaveSuffixRule = new ClassNameMustHaveSuffixRule(
            layer: 'Controller',
            suffix: 'Controller'
        );

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $classNameMustHaveSuffixRule->evaluate($this->makeNode('App\\Controller\\OrderController'))
        );
    }

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $classNameMustHaveSuffixRule = new ClassNameMustHaveSuffixRule(
            layer: 'Controller',
            suffix: 'Controller'
        );

        $this->assertTrue($classNameMustHaveSuffixRule->appliesTo($this->makeNode('App\\Controller\\OrderController')));
        $this->assertFalse($classNameMustHaveSuffixRule->appliesTo(
            $this->makeNode('App\\Controller\\OrderController', 'Service')
        ));
    }

    public function testViolatesWhenClassNameDoesNotHaveSuffix(): void
    {
        $classNameMustHaveSuffixRule = new ClassNameMustHaveSuffixRule(
            layer: 'Controller',
            suffix: 'Controller'
        );

        $violation = $classNameMustHaveSuffixRule->evaluate($this->makeNode('App\\Controller\\OrderAction'));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('Controller', $violation->message);
    }

    private function makeNode(string $className, string $layer = 'Controller'): ClassNode
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
