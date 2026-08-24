<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustNotHavePrefixRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassNameMustNotHavePrefixRule::class)]
final class ClassNameMustNotHavePrefixRuleTest extends TestCase
{
    public function testPassesWhenClassNameDoesNotHavePrefix(): void
    {
        $classNameMustNotHavePrefixRule = new ClassNameMustNotHavePrefixRule(
            layer: 'Model',
            prefix: 'Model'
        );

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $classNameMustNotHavePrefixRule->evaluate($this->makeNode('App\\Model\\Order'))
        );
    }

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $classNameMustNotHavePrefixRule = new ClassNameMustNotHavePrefixRule(
            layer: 'Model',
            prefix: 'Model'
        );

        $this->assertTrue($classNameMustNotHavePrefixRule->appliesTo($this->makeNode('App\\Model\\Order')));
        $this->assertFalse($classNameMustNotHavePrefixRule->appliesTo($this->makeNode(
            'App\\Service\\ModelOrder',
            'Service'
        )));
    }

    public function testViolatesWhenClassNameHasPrefix(): void
    {
        $classNameMustNotHavePrefixRule = new ClassNameMustNotHavePrefixRule(
            layer: 'Model',
            prefix: 'Model'
        );

        $violation = $classNameMustNotHavePrefixRule->evaluate($this->makeNode('App\\Model\\ModelOrder'));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Class [App\\Model\\ModelOrder] must not have prefix [Model]', $violation->message);
    }

    #[DataProvider('nonClassKindProvider')]
    public function testViolationMessageNamesTheClassLikeKind(
        string $expectedKind,
        bool $isInterface,
        bool $isTrait,
        bool $isEnum,
    ): void {
        $classNameMustNotHavePrefixRule = new ClassNameMustNotHavePrefixRule(
            layer: 'Model',
            prefix: 'Model'
        );

        $violation = $classNameMustNotHavePrefixRule->evaluate($this->makeNode(
            'App\\Model\\ModelOrder',
            isInterface: $isInterface,
            isTrait: $isTrait,
            isEnum: $isEnum,
        ));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\Model\\ModelOrder] must not have prefix [Model]',
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
        string $layer = 'Model',
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
    ): ClassNode {
        return new ClassNode(
            className:   $className,
            file:        '/fake.php',
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
}
