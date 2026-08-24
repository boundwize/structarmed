<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\NamingConventionRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamingConventionRule::class)]
final class NamingConventionRuleTest extends TestCase
{
    private function makeNode(
        string $className,
        string $layer,
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

    public function testPassesWhenClassIsInCorrectLayer(): void
    {
        $namingConventionRule = new NamingConventionRule(
            classNamePattern: '/Service$/',
            mustBeInLayer: 'Application'
        );
        $classNode            = $this->makeNode('App\\Application\\OrderService', 'Application');

        $this->assertNotInstanceOf(RuleViolation::class, $namingConventionRule->evaluate($classNode));
    }

    public function testViolatesWhenClassIsInWrongLayer(): void
    {
        $namingConventionRule = new NamingConventionRule(
            classNamePattern: '/Service$/',
            mustBeInLayer: 'Application'
        );
        $classNode            = $this->makeNode('App\\Infrastructure\\OrderService', 'Infrastructure');

        $violation = $namingConventionRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Class [App\\Infrastructure\\OrderService] matching pattern [/Service$/] must live in layer [Application], '
            . 'found in layer [Infrastructure]',
            $violation->message
        );
    }

    #[DataProvider('nonClassKindProvider')]
    public function testViolationMessageNamesTheClassLikeKind(
        string $expectedKind,
        bool $isInterface,
        bool $isTrait,
        bool $isEnum,
    ): void {
        $namingConventionRule = new NamingConventionRule(
            classNamePattern: '/Service$/',
            mustBeInLayer: 'Application'
        );
        $classNode            = $this->makeNode(
            'App\\Infrastructure\\OrderService',
            'Infrastructure',
            isInterface: $isInterface,
            isTrait: $isTrait,
            isEnum: $isEnum,
        );

        $this->assertTrue($namingConventionRule->appliesTo($classNode));

        $violation = $namingConventionRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\Infrastructure\\OrderService] matching pattern [/Service$/] '
            . 'must live in layer [Application], found in layer [Infrastructure]',
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

    public function testExcludesInterfacesWhenFlagSet(): void
    {
        $namingConventionRule = new NamingConventionRule(
            classNamePattern: '/Repository$/',
            mustBeInLayer: 'Infrastructure',
            excludeInterfaces: true
        );
        $classNode            = $this->makeNode('App\\Domain\\OrderRepository', 'Domain', isInterface: true);

        $this->assertFalse($namingConventionRule->appliesTo($classNode));
    }

    public function testExcludePatternSkipsMatchingClasses(): void
    {
        $namingConventionRule = new NamingConventionRule(
            classNamePattern: '/Service$/',
            mustBeInLayer: 'Application',
            excludePattern: '/DomainService$/'
        );
        $classNode            = $this->makeNode('App\\Domain\\OrderDomainService', 'Domain');

        $this->assertFalse($namingConventionRule->appliesTo($classNode));
    }

    public function testExcludePatternMatchesFullyQualifiedClassName(): void
    {
        $namingConventionRule = new NamingConventionRule(
            classNamePattern: '/Service$/',
            mustBeInLayer: 'Application',
            excludePattern: '/^App\\\\Domain\\\\/'
        );
        $classNode            = $this->makeNode('App\\Domain\\OrderDomainService', 'Domain');

        $this->assertFalse($namingConventionRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingFullyQualifiedClassNamePattern(): void
    {
        $namingConventionRule = new NamingConventionRule(
            classNamePattern: '/^App\\\\Application\\\\/',
            mustBeInLayer: 'Application'
        );
        $classNode            = $this->makeNode('App\\Application\\OrderService', 'Application');

        $this->assertTrue($namingConventionRule->appliesTo($classNode));
    }

    public function testDoesNotApplyWhenPatternDoesNotMatch(): void
    {
        $namingConventionRule = new NamingConventionRule(
            classNamePattern: '/Handler$/',
            mustBeInLayer: 'Application'
        );
        $classNode            = $this->makeNode('App\\Application\\OrderService', 'Application');

        $this->assertFalse($namingConventionRule->appliesTo($classNode));
    }
}
