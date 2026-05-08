<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseSuperglobalsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotUseSuperglobalsRule::class)]
final class MayNotUseSuperglobalsRuleTest extends TestCase
{
    /** @param array<string> $superglobals */
    private function makeNode(array $superglobals, string $layer = 'Model'): ClassNode
    {
        return new ClassNode(
            className:    'App\\Model\\OrderModel',
            file:         '/fake.php',
            line:         1,
            layer:        $layer,
            extends:      null,
            isAbstract:   false,
            isFinal:      true,
            isInterface:  false,
            isReadonly:   false,
            superglobals: $superglobals,
        );
    }

    public function testPassesWhenNoSuperglobalsAccessed(): void
    {
        $mayNotUseSuperglobalsRule = new MayNotUseSuperglobalsRule(layer: 'Model');
        $classNode                 = $this->makeNode([]);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseSuperglobalsRule->evaluate($classNode));
    }

    public function testViolatesWhenGetAccessed(): void
    {
        $mayNotUseSuperglobalsRule = new MayNotUseSuperglobalsRule(layer: 'Model');
        $classNode                 = $this->makeNode(['$_GET']);

        $violation = $mayNotUseSuperglobalsRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('$_GET', $violation->message);
    }

    public function testViolatesWhenPostAccessed(): void
    {
        $mayNotUseSuperglobalsRule = new MayNotUseSuperglobalsRule(layer: 'Model');
        $classNode                 = $this->makeNode(['$_POST']);

        $this->assertInstanceOf(RuleViolation::class, $mayNotUseSuperglobalsRule->evaluate($classNode));
    }

    public function testViolatesWhenMultipleSuperglobalsAccessed(): void
    {
        $mayNotUseSuperglobalsRule = new MayNotUseSuperglobalsRule(layer: 'Model');
        $classNode                 = $this->makeNode(['$_GET', '$_SESSION']);

        $violation = $mayNotUseSuperglobalsRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('$_GET', $violation->message);
        $this->assertStringContainsString('$_SESSION', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mayNotUseSuperglobalsRule = new MayNotUseSuperglobalsRule(layer: 'Model');
        $classNode                 = $this->makeNode(['$_GET'], layer: 'Controller');

        $this->assertFalse($mayNotUseSuperglobalsRule->appliesTo($classNode));
    }
}
