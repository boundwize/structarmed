<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotCallFunctionRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotCallFunctionRule::class)]
final class MayNotCallFunctionRuleTest extends TestCase
{
    /** @param array<string> $functionCalls */
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
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $classNode              = $this->makeNode(['array_map', 'array_filter']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotCallFunctionRule->evaluate($classNode));
    }

    public function testViolatesWhenForbiddenFunctionIsCalled(): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $classNode              = $this->makeNode(['var_dump']);

        $violation = $mayNotCallFunctionRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('var_dump', $violation->message);
    }

    public function testViolatesForDdFunction(): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'dd');
        $classNode              = $this->makeNode(['dd']);

        $this->assertInstanceOf(RuleViolation::class, $mayNotCallFunctionRule->evaluate($classNode));
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $classNode              = $this->makeNode(['var_dump'], layer: 'Infrastructure');

        $this->assertFalse($mayNotCallFunctionRule->appliesTo($classNode));
    }

    public function testAppliesToCorrectLayer(): void
    {
        $mayNotCallFunctionRule = new MayNotCallFunctionRule(layer: 'Domain', function: 'var_dump');
        $classNode              = $this->makeNode([]);

        $this->assertTrue($mayNotCallFunctionRule->appliesTo($classNode));
    }
}
