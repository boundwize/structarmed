<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeInterfaceRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MustBeInterfaceRule::class)]
final class MustBeInterfaceRuleTest extends TestCase
{
    private function makeNode(
        string $className = 'App\\Domain\\Repositories\\OrderRepository',
        string $layer = 'Domain',
        bool $isInterface = false,
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
        );
    }

    public function testPassesWhenClassIsInterface(): void
    {
        $rule = new MustBeInterfaceRule(layer: 'Domain', classNamePattern: '/Repository$/');
        $node = $this->makeNode(isInterface: true);

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenClassIsNotInterface(): void
    {
        $rule = new MustBeInterfaceRule(layer: 'Domain', classNamePattern: '/Repository$/');
        $node = $this->makeNode(isInterface: false);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('interface', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $rule = new MustBeInterfaceRule(layer: 'Domain');
        $node = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($rule->appliesTo($node));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $rule = new MustBeInterfaceRule(layer: 'Domain', classNamePattern: '/Repository$/');
        $node = $this->makeNode();

        $this->assertTrue($rule->appliesTo($node));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $rule = new MustBeInterfaceRule(layer: 'Domain');
        $node = $this->makeNode();

        $this->assertTrue($rule->appliesTo($node));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $rule = new MustBeInterfaceRule(layer: 'Domain', classNamePattern: '/Repository$/');
        $node = $this->makeNode(className: 'App\\Domain\\Services\\OrderService');

        $this->assertFalse($rule->appliesTo($node));
    }
}
