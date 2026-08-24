<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustBeStudlyCapsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassNameMustBeStudlyCapsRule::class)]
final class ClassNameMustBeStudlyCapsRuleTest extends TestCase
{
    public function testPassesStudlyCapsClassName(): void
    {
        $classNameMustBeStudlyCapsRule = new ClassNameMustBeStudlyCapsRule('Source');

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $classNameMustBeStudlyCapsRule->evaluate($this->makeNode('App\\OrderService'))
        );
    }

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $classNameMustBeStudlyCapsRule = new ClassNameMustBeStudlyCapsRule('Source');

        $this->assertTrue($classNameMustBeStudlyCapsRule->appliesTo($this->makeNode('App\\OrderService')));
        $this->assertFalse($classNameMustBeStudlyCapsRule->appliesTo($this->makeNode('App\\OrderService', 'Other')));
    }

    public function testViolatesNonStudlyCapsClassName(): void
    {
        $classNameMustBeStudlyCapsRule = new ClassNameMustBeStudlyCapsRule('Source');

        $this->assertInstanceOf(
            RuleViolation::class,
            $classNameMustBeStudlyCapsRule->evaluate($this->makeNode('App\\order_service'))
        );
    }

    public function testViolationMessageNamesClassForPlainClass(): void
    {
        $classNameMustBeStudlyCapsRule = new ClassNameMustBeStudlyCapsRule('Source');

        $violation = $classNameMustBeStudlyCapsRule->evaluate($this->makeNode('App\\order_service'));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Class [App\\order_service] must be declared in StudlyCaps', $violation->message);
    }

    #[DataProvider('nonClassKindProvider')]
    public function testViolationMessageNamesTheClassLikeKind(
        string $expectedKind,
        bool $isInterface,
        bool $isTrait,
        bool $isEnum,
    ): void {
        $classNameMustBeStudlyCapsRule = new ClassNameMustBeStudlyCapsRule('Source');

        $violation = $classNameMustBeStudlyCapsRule->evaluate($this->makeNode(
            'App\\order_service',
            isInterface: $isInterface,
            isTrait: $isTrait,
            isEnum: $isEnum,
        ));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\order_service] must be declared in StudlyCaps',
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

    private function makeNode(
        string $className,
        string $layer = 'Source',
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
    ): ClassNode {
        return new ClassNode(
            className: $className,
            file: '/fake.php',
            line: 1,
            layer: $layer,
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: $isInterface,
            isReadonly: false,
            isTrait: $isTrait,
            isEnum: $isEnum,
        );
    }
}
