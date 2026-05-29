<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Usage\MayNotUseNamespaceRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MayNotUseNamespaceRule::class)]
final class MayNotUseNamespaceRuleTest extends TestCase
{
    /** @param list<string> $dependencies */
    private function makeNode(array $dependencies, string $layer = 'Domain'): ClassNode
    {
        return new ClassNode(
            className:    'App\\Domain\\OrderValueObject',
            file:         '/fake.php',
            line:         1,
            layer:        $layer,
            extends:      null,
            isAbstract:   false,
            isFinal:      true,
            isInterface:  false,
            isReadonly:   false,
            dependencies: $dependencies,
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
        $this->assertStringContainsString('Doctrine\\ORM', $violation->message);
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
