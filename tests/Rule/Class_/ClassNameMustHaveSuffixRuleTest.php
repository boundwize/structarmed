<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\ClassNameMustHaveSuffixRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassNameMustHaveSuffixRule::class)]
final class ClassNameMustHaveSuffixRuleTest extends TestCase
{
    public function testPassesWhenClassNameHasSuffix(): void
    {
        $classNameMustHaveSuffixRule = new ClassNameMustHaveSuffixRule(
            layer: 'Controller',
            suffix: 'Controller'
        );

        $this->assertNotInstanceOf(
            RuleViolation::class,
            $classNameMustHaveSuffixRule->evaluate($this->makeNode('App\\Controller\\OrderController'))
        );
    }

    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $classNameMustHaveSuffixRule = new ClassNameMustHaveSuffixRule(
            layer: 'Controller',
            suffix: 'Controller'
        );

        $this->assertTrue($classNameMustHaveSuffixRule->appliesTo($this->makeNode('App\\Controller\\OrderController')));
        $this->assertFalse($classNameMustHaveSuffixRule->appliesTo(
            $this->makeNode('App\\Controller\\OrderController', 'Service')
        ));
    }

    public function testViolatesWhenClassNameDoesNotHaveSuffix(): void
    {
        $classNameMustHaveSuffixRule = new ClassNameMustHaveSuffixRule(
            layer: 'Controller',
            suffix: 'Controller'
        );

        $violation = $classNameMustHaveSuffixRule->evaluate($this->makeNode('App\\Controller\\OrderAction'));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Class [App\\Controller\\OrderAction] must have suffix [Controller]', $violation->message);
    }

    #[DataProvider('nonClassKindProvider')]
    public function testViolationMessageNamesTheClassLikeKind(
        string $expectedKind,
        bool $isInterface,
        bool $isTrait,
        bool $isEnum,
    ): void {
        $classNameMustHaveSuffixRule = new ClassNameMustHaveSuffixRule(
            layer: 'Controller',
            suffix: 'Controller'
        );

        $violation = $classNameMustHaveSuffixRule->evaluate($this->makeNode(
            'App\\Controller\\OrderAction',
            isInterface: $isInterface,
            isTrait: $isTrait,
            isEnum: $isEnum,
        ));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\Controller\\OrderAction] must have suffix [Controller]',
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
        string $layer = 'Controller',
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
