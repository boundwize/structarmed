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
        $this->assertStringContainsString(JsonSerializable::class, $violation->message);
    }

    /**
     * @param string[] $implements
     */
    private function makeNode(array $implements, string $layer = 'Domain'): ClassNode
    {
        return new ClassNode(
            className:   'App\\Domain\\Order',
            file:        '/fake.php',
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     false,
            isInterface: false,
            isReadonly:  false,
            implements:  $implements,
        );
    }
}
