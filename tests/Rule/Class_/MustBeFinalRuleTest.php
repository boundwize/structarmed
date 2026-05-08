<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MustBeFinalRule::class)]
final class MustBeFinalRuleTest extends TestCase
{
    private function makeNode(
        string $className = 'App\\Domain\\Entities\\OrderEntity',
        string $layer = 'Domain',
        bool $isFinal = false,
        bool $isInterface = false,
    ): ClassNode {
        return new ClassNode(
            className:  $className,
            file:       '/src/Domain/Entities/OrderEntity.php',
            line:       1,
            layer:      $layer,
            extends:    null,
            isAbstract: false,
            isFinal:    $isFinal,
            isInterface: $isInterface,
            isReadonly: false,
        );
    }

    public function testPassesWhenClassIsFinal(): void
    {
        $rule = new MustBeFinalRule(layer: 'Domain');
        $node = $this->makeNode(isFinal: true);

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenClassIsNotFinal(): void
    {
        $rule = new MustBeFinalRule(layer: 'Domain');
        $node = $this->makeNode(isFinal: false);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('final', $violation->message);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $rule = new MustBeFinalRule(layer: 'Domain');
        $node = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($rule->appliesTo($node));
    }

    public function testDoesNotApplyToInterfaces(): void
    {
        $rule = new MustBeFinalRule(layer: 'Domain');
        $node = $this->makeNode(isInterface: true);

        $this->assertFalse($rule->appliesTo($node));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $rule = new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$/');
        $node = $this->makeNode(className: 'App\\Domain\\OrderEntity');

        $this->assertTrue($rule->appliesTo($node));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $rule = new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$/');
        $node = $this->makeNode(className: 'App\\Domain\\OrderService');

        $this->assertFalse($rule->appliesTo($node));
    }
}
