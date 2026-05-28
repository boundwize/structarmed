<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseLanguageConstructRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotUseLanguageConstructRule::class)]
final class MayNotUseLanguageConstructRuleTest extends TestCase
{
    /** @param array<string> $languageConstructs */
    private function makeNode(array $languageConstructs, string $layer = 'Domain'): ClassNode
    {
        return new ClassNode(
            className:          'App\\Domain\\OrderService',
            file:               '/fake.php',
            line:               1,
            layer:              $layer,
            extends:            null,
            isAbstract:         false,
            isFinal:            true,
            isInterface:        false,
            isReadonly:         false,
            languageConstructs: $languageConstructs,
        );
    }

    public function testPassesWhenForbiddenConstructNotUsed(): void
    {
        $mayNotUseLanguageConstructRule = new MayNotUseLanguageConstructRule(layer: 'Domain', construct: 'exit');
        $classNode                      = $this->makeNode([]);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseLanguageConstructRule->evaluate($classNode));
    }

    public function testViolatesWhenExitIsUsed(): void
    {
        $mayNotUseLanguageConstructRule = new MayNotUseLanguageConstructRule(layer: 'Domain', construct: 'exit');
        $classNode                      = $this->makeNode(['exit']);

        $violation = $mayNotUseLanguageConstructRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('exit', $violation->message);
        $this->assertStringContainsString('App\\Domain\\OrderService', $violation->message);
    }

    public function testViolatesWhenDieIsUsed(): void
    {
        $mayNotUseLanguageConstructRule = new MayNotUseLanguageConstructRule(layer: 'Domain', construct: 'die');
        $classNode                      = $this->makeNode(['die']);

        $violation = $mayNotUseLanguageConstructRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('die', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mayNotUseLanguageConstructRule = new MayNotUseLanguageConstructRule(layer: 'Domain', construct: 'exit');
        $classNode                      = $this->makeNode(['exit'], layer: 'Infrastructure');

        $this->assertFalse($mayNotUseLanguageConstructRule->appliesTo($classNode));
    }

    public function testAppliesToCorrectLayer(): void
    {
        $mayNotUseLanguageConstructRule = new MayNotUseLanguageConstructRule(layer: 'Domain', construct: 'exit');
        $classNode                      = $this->makeNode([]);

        $this->assertTrue($mayNotUseLanguageConstructRule->appliesTo($classNode));
    }
}
