<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Rule\Rules\Method\MethodNameMustBeCamelCaseRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MethodNameMustBeCamelCaseRule::class)]
final class MethodNameMustBeCamelCaseRuleTest extends TestCase
{
    public function testAppliesOnlyToConfiguredLayer(): void
    {
        $methodNameMustBeCamelCaseRule = new MethodNameMustBeCamelCaseRule('Source');

        $this->assertTrue($methodNameMustBeCamelCaseRule->appliesTo($this->makeNode([], 'Source')));
        $this->assertFalse($methodNameMustBeCamelCaseRule->appliesTo($this->makeNode([], 'Other')));
    }

    public function testEvaluateReturnsFirstViolation(): void
    {
        $methodNameMustBeCamelCaseRule = new MethodNameMustBeCamelCaseRule('Source');

        $violation = $methodNameMustBeCamelCaseRule->evaluate($this->makeNode([
            new MethodNode('Bad_name', 'public', true, false, 0, 1, 1),
        ]));

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(1, $violation->line);
    }

    public function testPassesCamelCaseMethodsAndIgnoresMagicMethods(): void
    {
        $methodNameMustBeCamelCaseRule = new MethodNameMustBeCamelCaseRule('Source');

        $this->assertSame([], $methodNameMustBeCamelCaseRule->evaluateAll($this->makeNode([
            new MethodNode('shipOrder', 'public', true, false, 0, 1, 1),
            new MethodNode('__construct', 'public', false, false, 0, 1, 1, isMagic: true),
            new MethodNode('__callStatic', 'public', true, false, 0, 1, 1, isMagic: true),
            new MethodNode('__set_state', 'public', true, false, 0, 1, 1, isMagic: true),
        ])));
    }

    public function testIgnoresPhpExtensionMethodsStartingWithDoubleUnderscore(): void
    {
        $methodNameMustBeCamelCaseRule = new MethodNameMustBeCamelCaseRule('Source');

        $this->assertSame([], $methodNameMustBeCamelCaseRule->evaluateAll($this->makeNode([
            new MethodNode('__doRequest', 'public', true, false, 0, 1, 1),
            new MethodNode('__getCookies', 'public', true, false, 0, 1, 1),
            new MethodNode('__getFunctions', 'public', true, false, 0, 1, 1),
            new MethodNode('__getLastRequest', 'public', true, false, 0, 1, 1),
            new MethodNode('__getLastRequestHeaders', 'public', true, false, 0, 1, 1),
            new MethodNode('__getLastResponse', 'public', true, false, 0, 1, 1),
            new MethodNode('__getLastResponseHeaders', 'public', true, false, 0, 1, 1),
            new MethodNode('__getTypes', 'public', true, false, 0, 1, 1),
            new MethodNode('__setCookie', 'public', true, false, 0, 1, 1),
            new MethodNode('__setLocation', 'public', true, false, 0, 1, 1),
            new MethodNode('__setSoapHeaders', 'public', true, false, 0, 1, 1),
            new MethodNode('__soapCall', 'public', true, false, 0, 1, 1),
        ])));
    }

    public function testViolatesNonMagicMethodsStartingWithDoubleUnderscore(): void
    {
        $methodNameMustBeCamelCaseRule = new MethodNameMustBeCamelCaseRule('Source');

        $violations = $methodNameMustBeCamelCaseRule->evaluateAll($this->makeNode([
            new MethodNode('__my_helper', 'public', true, false, 0, 1, 1, line: 1),
            new MethodNode('__DoThing', 'public', true, false, 0, 1, 1, line: 2),
        ]));

        $this->assertCount(2, $violations);
        $this->assertSame(1, $violations[0]->line);
        $this->assertSame(2, $violations[1]->line);
    }

    public function testViolatesNonCamelCaseMethods(): void
    {
        $methodNameMustBeCamelCaseRule = new MethodNameMustBeCamelCaseRule('Source');

        $violations = $methodNameMustBeCamelCaseRule->evaluateAll($this->makeNode([
            new MethodNode('Ship_Order', 'public', true, false, 0, 1, 1, line: 9),
        ]));

        $this->assertCount(1, $violations);
        $this->assertInstanceOf(RuleViolation::class, $violations[0]);
        $this->assertSame(9, $violations[0]->line);
    }

    /**
     * @param list<MethodNode> $methods
     */
    private function makeNode(array $methods, string $layer = 'Source'): ClassNode
    {
        return new ClassNode(
            className: 'App\\Order',
            file: '/fake.php',
            line: 1,
            layer: $layer,
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: false,
            isReadonly: false,
            methods: $methods,
        );
    }
}
