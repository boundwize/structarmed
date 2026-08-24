<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseClassRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotUseClassRule::class)]
final class MayNotUseClassRuleTest extends TestCase
{
    /** @param list<string> $dependencies */
    private function makeNode(
        array $dependencies,
        string $layer = 'Domain',
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
    ): ClassNode {
        return new ClassNode(
            className:    'App\\Domain\\OrderValueObject',
            file:         '/fake.php',
            line:         1,
            layer:        $layer,
            extends:      null,
            isAbstract:   false,
            isFinal:      true,
            isInterface:  $isInterface,
            isReadonly:   false,
            isTrait:      $isTrait,
            dependencies: $dependencies,
            isEnum:       $isEnum,
        );
    }

    public function testPassesWhenForbiddenClassNotUsed(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class);
        $classNode          = $this->makeNode(['DateTimeImmutable']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseClassRule->evaluate($classNode));
    }

    public function testPassesWhenDependencyOnlySharesForbiddenClassPrefix(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(
            layer: 'Domain',
            forbiddenClass: 'Vendor\\ForbiddenService'
        );
        $classNode          = $this->makeNode(['Vendor\\ForbiddenServiceExtra']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseClassRule->evaluate($classNode));
    }

    public function testViolatesWhenForbiddenClassIsUsed(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class);
        $classNode          = $this->makeNode([DateTime::class]);

        $violation = $mayNotUseClassRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Class [App\\Domain\\OrderValueObject] must not use [DateTime]', $violation->message);
    }

    #[DataProvider('nonClassKindProvider')]
    public function testViolationMessageNamesTheClassLikeKind(
        string $expectedKind,
        bool $isInterface,
        bool $isTrait,
        bool $isEnum,
    ): void {
        $mayNotUseClassRule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class);
        $classNode          = $this->makeNode(
            [DateTime::class],
            isInterface: $isInterface,
            isTrait: $isTrait,
            isEnum: $isEnum,
        );

        $violation = $mayNotUseClassRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\Domain\\OrderValueObject] must not use [DateTime]',
            $violation->message
        );
    }

    /** @return iterable<string, array{string, bool, bool, bool}> */
    public static function nonClassKindProvider(): iterable
    {
        yield 'interface' => ['Interface', true, false, false];
        yield 'trait'     => ['Trait', false, true, false];
        yield 'enum'      => ['Enum', false, false, true];
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class);
        $classNode          = $this->makeNode([DateTime::class], layer: 'Infrastructure');

        $this->assertFalse($mayNotUseClassRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingClassNamePattern(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(
            layer: 'Domain',
            forbiddenClass: DateTime::class,
            classNamePattern: '/ValueObject$/'
        );
        $classNode          = $this->makeNode([]);

        $this->assertTrue($mayNotUseClassRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingFullyQualifiedClassNamePattern(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(
            layer: 'Domain',
            forbiddenClass: DateTime::class,
            classNamePattern: '/^App\\\\Domain\\\\/'
        );
        $classNode          = $this->makeNode([]);

        $this->assertTrue($mayNotUseClassRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(layer: 'Domain', forbiddenClass: DateTime::class);
        $classNode          = $this->makeNode([]);

        $this->assertTrue($mayNotUseClassRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingClassNamePattern(): void
    {
        $mayNotUseClassRule = new MayNotUseClassRule(
            layer: 'Domain',
            forbiddenClass: DateTime::class,
            classNamePattern: '/Entity$/'
        );
        $classNode          = $this->makeNode([]);

        $this->assertFalse($mayNotUseClassRule->appliesTo($classNode));
    }
}
