<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseConstantRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotUseConstantRule::class)]
final class MayNotUseConstantRuleTest extends TestCase
{
    /** @param list<string> $constantFetches */
    private function makeNode(
        array $constantFetches,
        string $layer = 'Domain',
        bool $isTrait = false,
        bool $isEnum = false,
    ): ClassNode {
        return new ClassNode(
            className:       'App\\Domain\\OrderService',
            file:            '/fake.php',
            line:            1,
            layer:           $layer,
            extends:         null,
            isAbstract:      false,
            isFinal:         true,
            isInterface:     false,
            isReadonly:      false,
            isTrait:         $isTrait,
            isEnum:          $isEnum,
            constantFetches: $constantFetches,
        );
    }

    public function testPassesWhenForbiddenConstantNotUsed(): void
    {
        $mayNotUseConstantRule = new MayNotUseConstantRule(layer: 'Domain', constant: 'PHP_EOL');
        $classNode             = $this->makeNode(['PHP_INT_MAX']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseConstantRule->evaluate($classNode));
    }

    public function testViolatesWhenForbiddenConstantIsUsed(): void
    {
        $mayNotUseConstantRule = new MayNotUseConstantRule(layer: 'Domain', constant: 'PHP_EOL');
        $classNode             = $this->makeNode(['PHP_EOL']);

        $violation = $mayNotUseConstantRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Class [App\\Domain\\OrderService] must not use constant [PHP_EOL]', $violation->message);
    }

    #[DataProvider('traitAndEnumKindProvider')]
    public function testViolationMessageNamesTheClassLikeKind(string $expectedKind, bool $isTrait, bool $isEnum): void
    {
        $mayNotUseConstantRule = new MayNotUseConstantRule(layer: 'Domain', constant: 'PHP_EOL');
        $classNode             = $this->makeNode(['PHP_EOL'], isTrait: $isTrait, isEnum: $isEnum);

        $violation = $mayNotUseConstantRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\Domain\\OrderService] must not use constant [PHP_EOL]',
            $violation->message
        );
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function traitAndEnumKindProvider(): iterable
    {
        yield 'trait' => ['Trait', true, false];
        yield 'enum'  => ['Enum', false, true];
    }

    public function testConstantComparisonIsCaseSensitive(): void
    {
        $mayNotUseConstantRule = new MayNotUseConstantRule(layer: 'Domain', constant: 'PHP_EOL');
        $classNode             = $this->makeNode(['php_eol']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseConstantRule->evaluate($classNode));
    }

    public function testNamespaceComparisonIsCaseInsensitive(): void
    {
        $mayNotUseConstantRule = new MayNotUseConstantRule(layer: 'Domain', constant: 'Vendor\\Config\\DEBUG');

        $this->assertInstanceOf(
            RuleViolation::class,
            $mayNotUseConstantRule->evaluate($this->makeNode(['vendor\\config\\DEBUG']))
        );
        $this->assertNotInstanceOf(
            RuleViolation::class,
            $mayNotUseConstantRule->evaluate($this->makeNode(['Vendor\\Config\\debug']))
        );
    }

    public function testPassesWhenOnlyAClassOrFunctionOfThatNameIsUsed(): void
    {
        $mayNotUseConstantRule = new MayNotUseConstantRule(layer: 'Domain', constant: 'STDIN');
        $classNode             = new ClassNode(
            className:     'App\\Domain\\OrderService',
            file:          '/fake.php',
            line:          1,
            layer:         'Domain',
            extends:       null,
            isAbstract:    false,
            isFinal:       true,
            isInterface:   false,
            isReadonly:    false,
            dependencies:  ['STDIN'],
            functionCalls: ['STDIN'],
        );

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseConstantRule->evaluate($classNode));
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mayNotUseConstantRule = new MayNotUseConstantRule(layer: 'Domain', constant: 'PHP_EOL');
        $classNode             = $this->makeNode(['PHP_EOL'], layer: 'Infrastructure');

        $this->assertFalse($mayNotUseConstantRule->appliesTo($classNode));
    }

    public function testAppliesToCorrectLayer(): void
    {
        $mayNotUseConstantRule = new MayNotUseConstantRule(layer: 'Domain', constant: 'PHP_EOL');
        $classNode             = $this->makeNode([]);

        $this->assertTrue($mayNotUseConstantRule->appliesTo($classNode));
    }
}
