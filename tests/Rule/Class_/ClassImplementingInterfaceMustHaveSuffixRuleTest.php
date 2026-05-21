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
        $rule = new ClassImplementingInterfaceMustHaveSuffixRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE,
            suffix: 'Middleware'
        );

        $violation = $rule->evaluate($this->makeNode(
            className: 'App\\Http\\AuthMiddleware',
            implements: [self::MIDDLEWARE_INTERFACE]
        ));

        $this->assertNotInstanceOf(RuleViolation::class, $violation);
    }

    public function testViolatesWhenClassImplementingInterfaceIsMissingSuffix(): void
    {
        $rule = new ClassImplementingInterfaceMustHaveSuffixRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE,
            suffix: 'Middleware'
        );

        $violation = $rule->evaluate($this->makeNode(
            className: 'App\\Http\\Auth',
            implements: [self::MIDDLEWARE_INTERFACE]
        ));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('Middleware', $violation->message);
    }

    public function testAppliesOnlyToClassesInLayerImplementingInterface(): void
    {
        $rule = new ClassImplementingInterfaceMustHaveSuffixRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE,
            suffix: 'Middleware'
        );

        $this->assertTrue($rule->appliesTo($this->makeNode(
            className: 'App\\Http\\AuthMiddleware',
            implements: [self::MIDDLEWARE_INTERFACE]
        )));
        $this->assertFalse($rule->appliesTo($this->makeNode(
            className: 'App\\Http\\AuthMiddleware',
            implements: [],
        )));
        $this->assertFalse($rule->appliesTo($this->makeNode(
            className: 'App\\Http\\AuthMiddleware',
            layer: 'Tests',
            implements: [self::MIDDLEWARE_INTERFACE]
        )));
        $this->assertFalse($rule->appliesTo($this->makeNode(
            className: 'App\\Http\\AuthMiddleware',
            implements: [self::MIDDLEWARE_INTERFACE],
            isInterface: true
        )));
    }

    /**
     * @param string[] $implements
     */
    private function makeNode(
        string $className,
        array $implements,
        string $layer = 'Source',
        bool $isInterface = false,
    ): ClassNode {
        return new ClassNode(
            className:   $className,
            file:        '/fake.php',
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     false,
            isInterface: $isInterface,
            isReadonly:  false,
            implements:  $implements,
        );
    }
}
