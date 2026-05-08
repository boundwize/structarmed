<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotCallFunctionRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotCallFunctionRule::class)]
final class MayNotCallFunctionRuleTest extends TestCase
{
    private function makeNode(array $functionCalls, string $layer = 'Domain'): ClassNode
    {
        return new ClassNode(
            className:     'App\\Domain\\OrderService',
            file:          '/fake.php',
            line:          1,
            layer:         $layer,
            extends:       null,
            isAbstract:    false,
            isFinal:       true,
            isInterface:   false,
            isReadonly:    false,
            functionCalls: $functionCalls,
        );
    }

    public function testPassesWhenForbiddenFunctionNotCalled(): void
    {
        $rule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $node = $this->makeNode(['array_map', 'array_filter']);

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenForbiddenFunctionIsCalled(): void
    {
        $rule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $node = $this->makeNode(['var_dump']);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('var_dump', $violation->message);
    }

    public function testViolatesForDdFunction(): void
    {
        $rule = new MayNotCallFunctionRule(layer: 'Domain', function: 'dd');
        $node = $this->makeNode(['dd']);

        $this->assertNotNull($rule->evaluate($node));
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $rule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $node = $this->makeNode(['var_dump'], layer: 'Infrastructure');

        $this->assertFalse($rule->appliesTo($node));
    }

    public function testAppliesToCorrectLayer(): void
    {
        $rule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $node = $this->makeNode([]);

        $this->assertTrue($rule->appliesTo($node));
    }
}
