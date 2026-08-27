<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\MayNotImplementInterfaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use JsonSerializable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotImplementInterfaceRule::class)]
final class MayNotImplementInterfaceRuleTest extends TestCase
{
    public function testPassesWhenInterfaceIsNotImplemented(): void
    {
        $mayNotImplementInterfaceRule = new MayNotImplementInterfaceRule(
            layer: 'Domain',
            interface: JsonSerializable::class
        );

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mayNotImplementInterfaceRule->evaluate($this->makeNode([]))
        );
    }

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $mayNotImplementInterfaceRule = new MayNotImplementInterfaceRule(
            layer: 'Domain',
            interface: JsonSerializable::class
        );

        $this->assertTrue($mayNotImplementInterfaceRule->appliesTo($this->makeNode([])));
        $this->assertFalse($mayNotImplementInterfaceRule->appliesTo($this->makeNode([], 'Infrastructure')));
    }

    public function testViolatesWhenInterfaceIsImplemented(): void
    {
        $mayNotImplementInterfaceRule = new MayNotImplementInterfaceRule(
            layer: 'Domain',
            interface: JsonSerializable::class
        );

        $violation = $mayNotImplementInterfaceRule->evaluate($this->makeNode([JsonSerializable::class]));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Class [App\\Domain\\Order] must not implement interface [JsonSerializable]',
            $violation->message
        );
    }

    public function testViolationMessageNamesEnumWhenEnumImplementsInterface(): void
    {
        $mayNotImplementInterfaceRule = new MayNotImplementInterfaceRule(
            layer: 'Domain',
            interface: JsonSerializable::class
        );

        $violation = $mayNotImplementInterfaceRule->evaluate(
            $this->makeNode([JsonSerializable::class], isEnum: true)
        );

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Enum [App\\Domain\\Order] must not implement interface [JsonSerializable]',
            $violation->message
        );
    }

    public function testViolationMessageNamesInterfaceWhenInterfaceExtendsForbiddenInterface(): void
    {
        $mayNotImplementInterfaceRule = new MayNotImplementInterfaceRule(
            layer: 'Domain',
            interface: JsonSerializable::class
        );
        $classNode                    = $this->makeNode([], isInterface: true);
        $classNode->setRecursiveParents([], [JsonSerializable::class]);

        $violation = $mayNotImplementInterfaceRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Interface [App\\Domain\\Order] must not extend interface [JsonSerializable]',
            $violation->message
        );
    }

    public function testViolatesWhenInterfaceDirectlyExtendsForbiddenInterface(): void
    {
        $mayNotImplementInterfaceRule = new MayNotImplementInterfaceRule(
            layer: 'Domain',
            interface: JsonSerializable::class
        );

        $violation = $mayNotImplementInterfaceRule->evaluate($this->makeNode(
            [],
            isInterface: true,
            interfaceExtends: [JsonSerializable::class],
        ));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Interface [App\\Domain\\Order] must not extend interface [JsonSerializable]',
            $violation->message
        );
    }

    public function testViolatesWhenInterfaceIndirectlyExtendsForbiddenInterface(): void
    {
        $mayNotImplementInterfaceRule = new MayNotImplementInterfaceRule(
            layer: 'Domain',
            interface: JsonSerializable::class
        );

        // App\Domain\Order extends App\Domain\Serializable, which extends JsonSerializable.
        $classNode = $this->makeNode(
            [],
            isInterface: true,
            interfaceExtends: ['App\\Domain\\Serializable'],
        );
        $classNode->setRecursiveParents([], ['App\\Domain\\Serializable', JsonSerializable::class]);

        $violation = $mayNotImplementInterfaceRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Interface [App\\Domain\\Order] must not extend interface [JsonSerializable]',
            $violation->message
        );
    }

    /**
     * @param string[] $implements
     * @param string[] $interfaceExtends
     */
    private function makeNode(
        array $implements,
        string $layer = 'Domain',
        bool $isInterface = false,
        bool $isEnum = false,
        array $interfaceExtends = [],
    ): ClassNode {
        return new ClassNode(
            className:   'App\\Domain\\Order',
            file:        '/fake.php',
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     false,
            isInterface: $isInterface,
            isReadonly:  false,
            implements:       $implements,
            isEnum:           $isEnum,
            interfaceExtends: $interfaceExtends,
        );
    }
}
