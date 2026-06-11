<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassImplementingInterfaceMustHaveSuffixRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassImplementingInterfaceMustHaveSuffixRule::class)]
final class ClassImplementingInterfaceMustHaveSuffixRuleTest extends TestCase
{
    private const MIDDLEWARE_INTERFACE = 'Psr\\Http\\Server\\MiddlewareInterface';

    public function testPassesWhenClassImplementingInterfaceHasSuffix(): void
    {
        $classImplementingInterfaceMustHaveSuffixRule = new ClassImplementingInterfaceMustHaveSuffixRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE,
            suffix: 'Middleware'
        );

        $violation = $classImplementingInterfaceMustHaveSuffixRule->evaluate($this->makeNode(
            className: 'App\\Http\\AuthMiddleware',
            implements: [self::MIDDLEWARE_INTERFACE]
        ));

        $this->assertNotInstanceOf(RuleViolation::class, $violation);
    }

    public function testViolatesWhenClassImplementingInterfaceIsMissingSuffix(): void
    {
        $classImplementingInterfaceMustHaveSuffixRule = new ClassImplementingInterfaceMustHaveSuffixRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE,
            suffix: 'Middleware'
        );

        $violation = $classImplementingInterfaceMustHaveSuffixRule->evaluate($this->makeNode(
            className: 'App\\Http\\Auth',
            implements: [self::MIDDLEWARE_INTERFACE]
        ));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('Middleware', $violation->message);
    }

    public function testPassesWhenInterfaceExtendingInterfaceHasSuffix(): void
    {
        $classImplementingInterfaceMustHaveSuffixRule = new ClassImplementingInterfaceMustHaveSuffixRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE,
            suffix: 'Middleware'
        );

        $violation = $classImplementingInterfaceMustHaveSuffixRule->evaluate($this->makeNode(
            className:        'App\\Http\\AuthMiddleware',
            implements:       [],
            isInterface:      true,
            interfaceExtends: [self::MIDDLEWARE_INTERFACE]
        ));

        $this->assertNotInstanceOf(RuleViolation::class, $violation);
    }

    public function testViolatesWhenInterfaceExtendingInterfaceIsMissingSuffix(): void
    {
        $classImplementingInterfaceMustHaveSuffixRule = new ClassImplementingInterfaceMustHaveSuffixRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE,
            suffix: 'Middleware'
        );

        $violation = $classImplementingInterfaceMustHaveSuffixRule->evaluate($this->makeNode(
            className:        'App\\Http\\Auth',
            implements:       [],
            isInterface:      true,
            interfaceExtends: [self::MIDDLEWARE_INTERFACE]
        ));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('Interface', $violation->message);
        $this->assertStringContainsString('extending interface', $violation->message);
    }

    public function testAppliesOnlyToClassesInLayerImplementingInterface(): void
    {
        $classImplementingInterfaceMustHaveSuffixRule = new ClassImplementingInterfaceMustHaveSuffixRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE,
            suffix: 'Middleware'
        );

        $this->assertTrue($classImplementingInterfaceMustHaveSuffixRule->appliesTo($this->makeNode(
            className: 'App\\Http\\AuthMiddleware',
            implements: [self::MIDDLEWARE_INTERFACE]
        )));
        $this->assertFalse($classImplementingInterfaceMustHaveSuffixRule->appliesTo($this->makeNode(
            className: 'App\\Http\\AuthMiddleware',
            implements: [],
        )));
        $this->assertFalse($classImplementingInterfaceMustHaveSuffixRule->appliesTo($this->makeNode(
            className: 'App\\Http\\AuthMiddleware',
            implements: [self::MIDDLEWARE_INTERFACE],
            layer: 'Tests'
        )));
        $this->assertFalse($classImplementingInterfaceMustHaveSuffixRule->appliesTo($this->makeNode(
            className: 'App\\Http\\AuthMiddleware',
            implements: [self::MIDDLEWARE_INTERFACE],
            isInterface: true
        )));
        $this->assertTrue($classImplementingInterfaceMustHaveSuffixRule->appliesTo($this->makeNode(
            className:        'App\\Http\\AuthMiddleware',
            implements:       [],
            isInterface:      true,
            interfaceExtends: [self::MIDDLEWARE_INTERFACE],
        )));
    }

    /**
     * @param string[] $implements
     * @param string[] $interfaceExtends
     */
    private function makeNode(
        string $className,
        array $implements,
        string $layer = 'Source',
        bool $isInterface = false,
        array $interfaceExtends = [],
    ): ClassNode {
        return new ClassNode(
            className:        $className,
            file:             '/fake.php',
            line:             1,
            layer:            $layer,
            extends:          null,
            isAbstract:       false,
            isFinal:          false,
            isInterface:      $isInterface,
            isReadonly:       false,
            implements:       $implements,
            interfaceExtends: $interfaceExtends,
        );
    }
}
