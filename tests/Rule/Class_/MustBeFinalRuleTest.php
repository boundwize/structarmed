<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_\AddFinalClassVisitor;
use Boundwize\StructArmed\Rule\Rules\Class_\MustBeFinalRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(MustBeFinalRule::class)]
final class MustBeFinalRuleTest extends TestCase
{
    private function makeNode(
        string $className = 'App\\Domain\\Entities\\OrderEntity',
        string $layer = 'Domain',
        bool $isFinal = false,
        bool $isInterface = false,
        bool $isTrait = false,
        bool $isEnum = false,
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
            isTrait:    $isTrait,
            isEnum:     $isEnum,
        );
    }

    public function testPassesWhenClassIsFinal(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain');
        $classNode       = $this->makeNode(isFinal: true);

        $this->assertNotInstanceOf(RuleViolation::class, $mustBeFinalRule->evaluate($classNode));
    }

    public function testViolatesWhenClassIsNotFinal(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain');
        $classNode       = $this->makeNode(isFinal: false);

        $violation = $mustBeFinalRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('final', $violation->message);
    }

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new MustBeFinalRule(layer: 'Domain'));
    }

    public function testCreatesFinalClassFixerVisitor(): void
    {
        $mustBeFinalRule      = new MustBeFinalRule(layer: 'Domain');
        $reflectionMethod     = new ReflectionMethod($mustBeFinalRule, 'createFixerVisitor');
        $addFinalClassVisitor = $reflectionMethod->invoke(
            $mustBeFinalRule,
            new RuleViolation(
                message:   'Class [App\\Order] must be declared final',
                file:      '/src/Order.php',
                line:      1,
                className: 'App\\Order',
                layer:     'Domain',
            )
        );

        $this->assertInstanceOf(AddFinalClassVisitor::class, $addFinalClassVisitor);
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain');
        $classNode       = $this->makeNode(layer: 'Infrastructure');

        $this->assertFalse($mustBeFinalRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToInterfaces(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain');
        $classNode       = $this->makeNode(isInterface: true);

        $this->assertFalse($mustBeFinalRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToTraits(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain');
        $classNode       = $this->makeNode(isTrait: true);

        $this->assertFalse($mustBeFinalRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToEnums(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain');
        $classNode       = $this->makeNode(isEnum: true);

        $this->assertFalse($mustBeFinalRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$/');
        $classNode       = $this->makeNode(className: 'App\\Domain\\OrderEntity');

        $this->assertTrue($mustBeFinalRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingFullyQualifiedClassNamePattern(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain', classNamePattern: '/^Boundwize\\\\StructArmed\\\\/');
        $classNode       = $this->makeNode(className: Preset::class);

        $this->assertTrue($mustBeFinalRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain');
        $classNode       = $this->makeNode();

        $this->assertTrue($mustBeFinalRule->appliesTo($classNode));
    }

    public function testDoesNotApplyToNonMatchingPattern(): void
    {
        $mustBeFinalRule = new MustBeFinalRule(layer: 'Domain', classNamePattern: '/Entity$/');
        $classNode       = $this->makeNode(className: 'App\\Domain\\OrderService');

        $this->assertFalse($mustBeFinalRule->appliesTo($classNode));
    }
}
