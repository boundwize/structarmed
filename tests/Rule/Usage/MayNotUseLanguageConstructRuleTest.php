<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseLanguageConstructRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * `die` is a pure alias of `exit`, so a rule banning either spelling must
     * fire whichever spelling the code actually used. `exit;`/`exit(1);` are
     * recorded as `exit`; `die;`/`die(1);` as `die` — the two recorded spellings
     * cover the four code forms.
     *
     * @param array<string> $recordedConstructs
     */
    #[DataProvider('exitDieAliasProvider')]
    public function testExitAndDieAreSymmetricAliases(string $configured, array $recordedConstructs): void
    {
        $mayNotUseLanguageConstructRule = new MayNotUseLanguageConstructRule(layer: 'Domain', construct: $configured);
        $classNode                      = $this->makeNode($recordedConstructs);

        $violation = $mayNotUseLanguageConstructRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString($configured, $violation->message);
    }

    /** @return iterable<string, array{string, array<string>}> */
    public static function exitDieAliasProvider(): iterable
    {
        yield 'ban exit, code used exit' => ['exit', ['exit']];
        yield 'ban exit, code used die'  => ['exit', ['die']];
        yield 'ban die, code used die'   => ['die', ['die']];
        yield 'ban die, code used exit'  => ['die', ['exit']];
    }

    /**
     * The include family are genuinely distinct constructs and must not be
     * aliased to one another.
     */
    #[DataProvider('includeFamilyProvider')]
    public function testIncludeFamilyRemainsDistinct(string $configured, string $recorded): void
    {
        $mayNotUseLanguageConstructRule = new MayNotUseLanguageConstructRule(layer: 'Domain', construct: $configured);
        $classNode                      = $this->makeNode([$recorded]);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseLanguageConstructRule->evaluate($classNode));
    }

    /** @return iterable<string, array{string, string}> */
    public static function includeFamilyProvider(): iterable
    {
        yield 'include vs include_once' => ['include', 'include_once'];
        yield 'include vs require'      => ['include', 'require'];
        yield 'require vs require_once' => ['require', 'require_once'];
        yield 'require_once vs include' => ['require_once', 'include'];
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
