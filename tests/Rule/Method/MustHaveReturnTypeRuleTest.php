<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Method\MustHaveReturnTypeRule;
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
        $rule = new MustHaveReturnTypeRule(layer: 'Domain');
        $node = $this->makeNode([
            $this->method('handle', hasReturnType: true),
            $this->method('getName', hasReturnType: true),
        ]);

        $this->assertNull($rule->evaluate($node));
    }

    public function testViolatesWhenPublicMethodMissingReturnType(): void
    {
        $rule = new MustHaveReturnTypeRule(layer: 'Domain');
        $node = $this->makeNode([
            $this->method('handle', hasReturnType: false),
        ]);

        $violation = $rule->evaluate($node);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('handle', $violation->message);
    }

    public function testIgnoresConstructor(): void
    {
        $rule = new MustHaveReturnTypeRule(layer: 'Domain');
        $node = $this->makeNode([
            $this->method('__construct', hasReturnType: false),
        ]);

        // Constructor missing return type should NOT trigger violation
        $this->assertNull($rule->evaluate($node));
    }

    public function testIgnoresPrivateMethods(): void
    {
        $rule = new MustHaveReturnTypeRule(layer: 'Domain');
        $node = $this->makeNode([
            $this->method('helper', hasReturnType: false, visibility: 'private'),
        ]);

        $this->assertNull($rule->evaluate($node));
    }

    public function testIgnoresProtectedMethods(): void
    {
        $rule = new MustHaveReturnTypeRule(layer: 'Domain');
        $node = $this->makeNode([
            $this->method('helper', hasReturnType: false, visibility: 'protected'),
        ]);

        $this->assertNull($rule->evaluate($node));
    }

    public function testDoesNotApplyToWrongLayer(): void
    {
        $rule = new MustHaveReturnTypeRule(layer: 'Domain');
        $node = $this->makeNode([], layer: 'Infrastructure');

        $this->assertFalse($rule->appliesTo($node));
    }

    public function testAppliesToMatchingPattern(): void
    {
        $rule = new MustHaveReturnTypeRule(layer: 'Domain', classNamePattern: '/Service$/');
        $node = $this->makeNode([]);

        $this->assertTrue($rule->appliesTo($node));
    }

    public function testAppliesToLayerWhenNoPatternConfigured(): void
    {
        $rule = new MustHaveReturnTypeRule(layer: 'Domain');
        $node = $this->makeNode([]);

        $this->assertTrue($rule->appliesTo($node));
    }
}
