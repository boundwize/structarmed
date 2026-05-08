<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\NamingConventionRule;
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
        $rule = new NamingConventionRule(
            classNamePattern: '/Service$/',
            mustBeInLayer: 'Application'
        );
        $node = $this->makeNode('App\\Application\\OrderService', 'Application');

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenClassIsInWrongLayer(): void
    {
        $rule = new NamingConventionRule(
            classNamePattern: '/Service$/',
            mustBeInLayer: 'Application'
        );
        $node = $this->makeNode('App\\Infrastructure\\OrderService', 'Infrastructure');

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('Application', $violation->message);
    }

    public function testExcludesInterfacesWhenFlagSet(): void
    {
        $rule = new NamingConventionRule(
            classNamePattern: '/Repository$/',
            mustBeInLayer: 'Infrastructure',
            excludeInterfaces: true
        );
        $node = $this->makeNode('App\\Domain\\OrderRepository', 'Domain', isInterface: true);

        $this->assertFalse($rule->appliesTo($node));
    }

    public function testExcludePatternSkipsMatchingClasses(): void
    {
        $rule = new NamingConventionRule(
            classNamePattern: '/Service$/',
            mustBeInLayer: 'Application',
            excludePattern: '/DomainService$/'
        );
        $node = $this->makeNode('App\\Domain\\OrderDomainService', 'Domain');

        $this->assertFalse($rule->appliesTo($node));
    }

    public function testDoesNotApplyWhenPatternDoesNotMatch(): void
    {
        $rule = new NamingConventionRule(
            classNamePattern: '/Handler$/',
            mustBeInLayer: 'Application'
        );
        $node = $this->makeNode('App\\Application\\OrderService', 'Application');

        $this->assertFalse($rule->appliesTo($node));
    }
}
