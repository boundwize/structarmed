<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassNode::class)]
final class ClassNodeTest extends TestCase
{
    public function testNameHelpersAndLayerChecks(): void
    {
        $classNode = new ClassNode(
            className:    'App\\Domain\\CreateOrderHandler',
            file:         '/src/CreateOrderHandler.php',
            line:         12,
            layer:        'Application',
            extends:      null,
            isAbstract:   false,
            isFinal:      true,
            isInterface:  false,
            isReadonly:   false,
        );

        $this->assertSame('CreateOrderHandler', $classNode->shortName());
        $this->assertTrue($classNode->isInLayer('Application'));
        $this->assertFalse($classNode->isInLayer('Domain'));
        $this->assertTrue($classNode->nameStartsWith('Create'));
        $this->assertTrue($classNode->nameEndsWith('Handler'));
        $this->assertTrue($classNode->nameMatches('/Order/'));
    }

    public function testDependencyInterfaceCallAndSuperglobalHelpers(): void
    {
        $classNode = new ClassNode(
            className:     'App\\Domain\\OrderService',
            file:          '/src/OrderService.php',
            line:          5,
            layer:         'Domain',
            extends:       null,
            isAbstract:    false,
            isFinal:       false,
            isInterface:   false,
            isReadonly:    false,
            dependencies:  ['App\\Infrastructure\\Clock'],
            implements:    ['App\\Contracts\\OrderService'],
            functionCalls: ['var_dump'],
            superglobals:  ['$_SERVER'],
        );

        $this->assertTrue($classNode->dependsOn('App\\Infrastructure'));
        $this->assertFalse($classNode->dependsOn('App\\Application'));
        $this->assertTrue($classNode->implementsInterface('App\\Contracts\\OrderService'));
        $this->assertTrue($classNode->callsFunction('var_dump'));
        $this->assertTrue($classNode->accessesSuperglobals());
    }

    public function testConstructorParamCountReturnsConstructorParamsOrZero(): void
    {
        $withConstructor = new ClassNode(
            className: 'App\\Domain\\Order',
            file: '/src/Order.php',
            line: 1,
            layer: 'Domain',
            extends: null,
            isAbstract: false,
            isFinal: true,
            isInterface: false,
            isReadonly: false,
            methods: [
                new MethodNode('__construct', 'public', false, false, 2, 1, 3),
            ],
        );

        $withoutConstructor = new ClassNode(
            className: 'App\\Domain\\Order',
            file: '/src/Order.php',
            line: 1,
            layer: 'Domain',
            extends: null,
            isAbstract: false,
            isFinal: true,
            isInterface: false,
            isReadonly: false,
        );

        $this->assertSame(2, $withConstructor->constructorParamCount());
        $this->assertSame(0, $withoutConstructor->constructorParamCount());
    }
}
