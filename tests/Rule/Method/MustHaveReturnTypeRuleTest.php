<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Method\MustHaveReturnTypeRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MustHaveReturnTypeRule::class)]
final class MustHaveReturnTypeRuleTest extends TestCase
{
    /** @param array<MethodNode> $methods */
    private function makeNode(array $methods, string $layer = 'Domain'): ClassNode
    {
        return new ClassNode(
            className:   'App\\Domain\\OrderService',
            file:        '/fake.php',
            line:        1,
            layer:       $layer,
            extends:     null,
            isAbstract:  false,
            isFinal:     true,
            isInterface: false,
            isReadonly:  false,
            methods:     $methods,
        );
    }

    private function method(
        string $name,
        bool $hasReturnType,
        string $visibility = 'public'
    ): MethodNode {
        return new MethodNode(
            name:                 $name,
            visibility:           $visibility,
            hasReturnType:        $hasReturnType,
            isStatic:             false,
            paramCount:           0,
            cyclomaticComplexity: 1,
            lineCount:            5,
        );
    }

    public function testPassesWhenAllPublicMethodsHaveReturnTypes(): void
    {
        $mustHaveReturnTypeRule = new MustHaveReturnTypeRule(layer: 'Domain');
        $classNode              = $this->makeNode([
            $this->method('handle', hasReturnType: true),
            $this->method('getName', hasReturnType: true),
        ]);

        $this->assertNotInstanceOf(RuleViolation::class, $mustHaveReturnTypeRule->evaluate($classNode));
    }

    public function testViolatesWhenPublicMethodMissingReturnType(): void
    {
        $mustHaveReturnTypeRule = new MustHaveReturnTypeRule(layer: 'Domain');
        $classNode              = $this->makeNode([
            $this->method('handle', hasReturnType: false),
        ]);

        $violation = $mustHaveReturnTypeRule->evaluate($classNode);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('handle', $violation->message);
    }

    public function testIgnoresConstructor(): void
    {
        $mustHaveReturnTypeRule = new MustHaveReturnTypeRule(layer: 'Domain');
        $classNode              = $this->makeNode([
            $this->method('__construct', hasReturnType: false),
        ]);

        // Constructor missing return type should NOT trigger violation
        $this->assertNotInstanceOf(RuleViolation::class, $mustHaveReturnTypeRule->evaluate($classNode));
    }

    public function testIgnoresPrivateMethods(): void
    {
        $mustHaveReturnTypeRule = new MustHaveReturnTypeRule(layer: 'Domain');
        $classNode              = $this->makeNode([
            $this->method('helper', hasReturnType: false, visibility: 'private'),
        ]);

        $this->assertNotInstanceOf(RuleViolation::class, $mustHaveReturnTypeRule->evaluate($classNode));
    }

    public function testIgnoresProtectedMethods(): void
    {
        $mustHaveReturnTypeRule = new MustHaveReturnTypeRule(layer: 'Domain');
        $classNode              = $this->makeNode([
            $this->method('helper', hasReturnType: false, visibility: 'protected'),
        ]);

        $this->assertNotInstanceOf(RuleViolation::class, $mustHaveReturnTypeRule->evaluate($classNode));
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $mustHaveReturnTypeRule = new MustHaveReturnTypeRule(layer: 'Domain');
        $classNode              = $this->makeNode([], layer: 'Infrastructure');

        $this->assertFalse($mustHaveReturnTypeRule->appliesTo($classNode));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $mustHaveReturnTypeRule = new MustHaveReturnTypeRule(layer: 'Domain', classNamePattern: '/Service$/');
        $classNode              = $this->makeNode([]);

        $this->assertTrue($mustHaveReturnTypeRule->appliesTo($classNode));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $mustHaveReturnTypeRule = new MustHaveReturnTypeRule(layer: 'Domain');
        $classNode              = $this->makeNode([]);

        $this->assertTrue($mustHaveReturnTypeRule->appliesTo($classNode));
    }
}
