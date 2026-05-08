<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseSuperglobalsRule;
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
        $rule = new MayNotUseSuperglobalsRule(layer: 'Model');
        $node = $this->makeNode([]);

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenGetAccessed(): void
    {
        $rule = new MayNotUseSuperglobalsRule(layer: 'Model');
        $node = $this->makeNode(['$_GET']);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('$_GET', $violation->message);
    }

    public function testViolatesWhenPostAccessed(): void
    {
        $rule = new MayNotUseSuperglobalsRule(layer: 'Model');
        $node = $this->makeNode(['$_POST']);

        $this->assertNotNull($rule->evaluate($node));
    }

    public function testViolatesWhenMultipleSuperglobalsAccessed(): void
    {
        $rule = new MayNotUseSuperglobalsRule(layer: 'Model');
        $node = $this->makeNode(['$_GET', '$_SESSION']);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('$_GET', $violation->message);
        $this->assertStringContainsString('$_SESSION', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $rule = new MayNotUseSuperglobalsRule(layer: 'Model');
        $node = $this->makeNode(['$_GET'], layer: 'Controller');

        $this->assertFalse($rule->appliesTo($node));
    }
}
