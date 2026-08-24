<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseNamespaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotUseNamespaceRule::class)]
final class MayNotUseNamespaceRuleTest extends TestCase
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

    public function testPassesWhenNoDepInForbiddenNamespace(): void
    {
        $mayNotUseNamespaceRule = new MayNotUseNamespaceRule(layer: 'Domain', forbiddenNamespace: 'Doctrine\\ORM');
        $classNode              = $this->makeNode(['App\\Domain\\SomeService']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseNamespaceRule->evaluate($classNode));
    }

    public function testViolatesWhenDepIsInForbiddenNamespace(): void
    {
        $mayNotUseNamespaceRule = new MayNotUseNamespaceRule(layer: 'Domain', forbiddenNamespace: 'Doctrine\\ORM');
        $classNode              = $this->makeNode(['Doctrine\\ORM\\EntityManager']);

        $violation = $mayNotUseNamespaceRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            'Class [App\\Domain\\OrderValueObject] must not use namespace [Doctrine\\ORM]',
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
        $mayNotUseNamespaceRule = new MayNotUseNamespaceRule(layer: 'Domain', forbiddenNamespace: 'Doctrine\\ORM');
        $classNode              = $this->makeNode(
            ['Doctrine\\ORM\\EntityManager'],
            isInterface: $isInterface,
            isTrait: $isTrait,
            isEnum: $isEnum,
        );

        $violation = $mayNotUseNamespaceRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(
            $expectedKind . ' [App\\Domain\\OrderValueObject] must not use namespace [Doctrine\\ORM]',
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

    public function testViolatesWhenForbiddenNamespaceHasTrailingBackslash(): void
    {
        $mayNotUseNamespaceRule = new MayNotUseNamespaceRule(layer: 'Domain', forbiddenNamespace: 'Doctrine\\ORM\\');
        $classNode              = $this->makeNode(['Doctrine\\ORM\\EntityRepository']);

        $this->assertInstanceOf(RuleViolation::class, $mayNotUseNamespaceRule->evaluate($classNode));
    }

    public function testPassesWhenDepOnlySharesNamespacePrefix(): void
    {
        $mayNotUseNamespaceRule = new MayNotUseNamespaceRule(layer: 'Domain', forbiddenNamespace: 'Doctrine\\ORM');
        $classNode              = $this->makeNode(['Doctrine\\ORMExtra\\Something']);

        $this->assertNotInstanceOf(RuleViolation::class, $mayNotUseNamespaceRule->evaluate($classNode));
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mayNotUseNamespaceRule = new MayNotUseNamespaceRule(layer: 'Domain', forbiddenNamespace: 'Doctrine\\ORM');
        $classNode              = $this->makeNode(['Doctrine\\ORM\\EntityManager'], layer: 'Infrastructure');

        $this->assertFalse($mayNotUseNamespaceRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingClassNamePattern(): void
    {
        $mayNotUseNamespaceRule = new MayNotUseNamespaceRule(
            layer: 'Domain',
            forbiddenNamespace: 'Doctrine\\ORM',
            classNamePattern: '/ValueObject$/'
        );
        $classNode              = $this->makeNode([]);

        $this->assertTrue($mayNotUseNamespaceRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mayNotUseNamespaceRule = new MayNotUseNamespaceRule(layer: 'Domain', forbiddenNamespace: 'Doctrine\\ORM');
        $classNode              = $this->makeNode([]);

        $this->assertTrue($mayNotUseNamespaceRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingClassNamePattern(): void
    {
        $mayNotUseNamespaceRule = new MayNotUseNamespaceRule(
            layer: 'Domain',
            forbiddenNamespace: 'Doctrine\\ORM',
            classNamePattern: '/Entity$/'
        );
        $classNode              = $this->makeNode([]);

        $this->assertFalse($mayNotUseNamespaceRule->appliesTo($classNode));
    }
}
