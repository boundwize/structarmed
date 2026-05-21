<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\MustImplementInterfaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MustImplementInterfaceRule::class)]
final class MustImplementInterfaceRuleTest extends TestCase
{
    private const MIDDLEWARE_INTERFACE = 'Psr\\Http\\Server\\MiddlewareInterface';

    public function testPassesWhenInterfaceIsImplemented(): void
    {
        $mustImplementInterfaceRule = new MustImplementInterfaceRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE
        );

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mustImplementInterfaceRule->evaluate($this->makeNode([self::MIDDLEWARE_INTERFACE]))
        );
    }

    public function testViolatesWhenInterfaceIsMissing(): void
    {
        $mustImplementInterfaceRule = new MustImplementInterfaceRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE
        );

        $violation = $mustImplementInterfaceRule->evaluate($this->makeNode([]));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString(self::MIDDLEWARE_INTERFACE, $violation->message);
    }

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $mustImplementInterfaceRule = new MustImplementInterfaceRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE
        );

        $this->assertTrue($mustImplementInterfaceRule->appliesTo($this->makeNode([])));
        $this->assertFalse($mustImplementInterfaceRule->appliesTo($this->makeNode([], 'Tests')));
    }

    public function testAppliesOnlyToMatchingClassNamePattern(): void
    {
        $mustImplementInterfaceRule = new MustImplementInterfaceRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE,
            classNamePattern: '/^App\\\\Http\\\\AuthMiddleware$/'
        );

        $this->assertTrue($mustImplementInterfaceRule->appliesTo($this->makeNode([])));
        $this->assertFalse($mustImplementInterfaceRule->appliesTo(
            $this->makeNode([], className: 'App\\Http\\HomeController')
        ));
    }

    public function testDoesNotApplyToInterfaces(): void
    {
        $mustImplementInterfaceRule = new MustImplementInterfaceRule(
            layer: 'Source',
            interface: self::MIDDLEWARE_INTERFACE
        );

        $this->assertFalse($mustImplementInterfaceRule->appliesTo(
            $this->makeNode([], isInterface: true)
        ));
    }

    /**
     * @param string[] $implements
     */
    private function makeNode(
        array $implements,
        string $layer = 'Source',
        string $className = 'App\\Http\\AuthMiddleware',
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
