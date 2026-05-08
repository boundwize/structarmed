<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\NamingConventionRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamingConventionRule::class)]
final class NamingConventionRuleTest extends TestCase
{
    private function makeNode(
        string $className,
        string $layer,
        bool $isInterface = false,
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
        $this->assertStringContainsString('Application', $violation->message);
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
