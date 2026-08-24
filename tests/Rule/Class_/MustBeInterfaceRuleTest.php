<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeInterfaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MustBeInterfaceRule::class)]
final class MustBeInterfaceRuleTest extends TestCase
{
    private function makeNode(
        string $className = 'App\\Domain\\Repositories\\OrderRepository',
        string $layer = 'Domain',
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
    ): ClassNode {
        return new ClassNode(
            className:   $className,
            file:        '/src/Domain/Repositories/OrderRepository.php',
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     false,
            isInterface: $isInterface,
            isReadonly:  false,
            isTrait:     $isTrait,
            isEnum:      $isEnum,
        );
    }

    public function testPassesWhenClassIsInterface(): void
    {
        $mustBeInterfaceRule = new MustBeInterfaceRule(layer: 'Domain', classNamePattern: '/Repository$/');
        $classNode           = $this->makeNode(isInterface: true);

        $this->assertNotInstanceOf(RuleViolation::class, $mustBeInterfaceRule->evaluate($classNode));
    }

    public function testViolatesWhenClassIsNotInterface(): void
    {
        $mustBeInterfaceRule = new MustBeInterfaceRule(layer: 'Domain', classNamePattern: '/Repository$/');
        $classNode           = $this->makeNode(isInterface: false);

        $violation = $mustBeInterfaceRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Class [App\\Domain\\Repositories\\OrderRepository] must be an interface',
            $violation->message
        );
    }

    #[DataProvider('traitAndEnumKindProvider')]
    public function testViolationMessageNamesTheClassLikeKind(string $expectedKind, bool $isTrait, bool $isEnum): void
    {
        $mustBeInterfaceRule = new MustBeInterfaceRule(layer: 'Domain', classNamePattern: '/Repository$/');
        $classNode           = $this->makeNode(isTrait: $isTrait, isEnum: $isEnum);

        $violation = $mustBeInterfaceRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\Domain\\Repositories\\OrderRepository] must be an interface',
            $violation->message
        );
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function traitAndEnumKindProvider(): iterable
    {
        yield 'trait' => ['Trait', true, false];
        yield 'enum'  => ['Enum', false, true];
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mustBeInterfaceRule = new MustBeInterfaceRule(layer: 'Domain');
        $classNode           = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($mustBeInterfaceRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $mustBeInterfaceRule = new MustBeInterfaceRule(layer: 'Domain', classNamePattern: '/Repository$/');
        $classNode           = $this->makeNode();

        $this->assertTrue($mustBeInterfaceRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingFullyQualifiedClassNamePattern(): void
    {
        $mustBeInterfaceRule = new MustBeInterfaceRule(
            layer: 'Domain',
            classNamePattern: '/^App\\\\Domain\\\\Repositories\\\\/'
        );
        $classNode           = $this->makeNode();

        $this->assertTrue($mustBeInterfaceRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mustBeInterfaceRule = new MustBeInterfaceRule(layer: 'Domain');
        $classNode           = $this->makeNode();

        $this->assertTrue($mustBeInterfaceRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $mustBeInterfaceRule = new MustBeInterfaceRule(layer: 'Domain', classNamePattern: '/Repository$/');
        $classNode           = $this->makeNode(className: 'App\\Domain\\Services\\OrderService');

        $this->assertFalse($mustBeInterfaceRule->appliesTo($classNode));
    }
}
