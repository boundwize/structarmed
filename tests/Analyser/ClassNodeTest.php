<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use App\Foo;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use DateTime;
use DateTimeImmutable;
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
        $this->assertTrue($classNode->isClass());
        $this->assertTrue($classNode->isInLayer('Application'));
        $this->assertFalse($classNode->isInLayer('Domain'));
        $this->assertTrue($classNode->nameStartsWith('Create'));
        $this->assertTrue($classNode->nameEndsWith('Handler'));
        $this->assertTrue($classNode->nameMatches('/Order/'));
        $this->assertFalse($classNode->nameMatches('/^App\\\\Domain\\\\CreateOrderHandler$/'));
        $this->assertTrue($classNode->nameMatches('/^App\\\\Domain\\\\CreateOrderHandler$/', isFullName: true));
    }

    public function testIsClassReturnsFalseForInterfacesAndTraits(): void
    {
        $interfaceNode = new ClassNode(
            className: 'App\\Contracts\\OrderService',
            file: '/src/OrderService.php',
            line: 1,
            layer: 'Contracts',
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: true,
            isReadonly: false,
        );

        $traitNode = new ClassNode(
            className: 'App\\Shared\\LogsMessages',
            file: '/src/LogsMessages.php',
            line: 1,
            layer: 'Shared',
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: false,
            isReadonly: false,
            isTrait: true,
        );

        $enumNode = new ClassNode(
            className: 'App\\Domain\\Status',
            file: '/src/Status.php',
            line: 1,
            layer: 'Domain',
            extends: null,
            isAbstract: false,
            isFinal: false,
            isInterface: false,
            isReadonly: false,
            isEnum: true,
        );

        $this->assertFalse($interfaceNode->isClass());
        $this->assertFalse($traitNode->isClass());
        $this->assertFalse($enumNode->isClass());
    }

    public function testDependencyInterfaceCallAndSuperglobalHelpers(): void
    {
        $classNode = new ClassNode(
            className:          'App\\Domain\\OrderService',
            file:               '/src/OrderService.php',
            line:               5,
            layer:              'Domain',
            extends:            null,
            isAbstract:         false,
            isFinal:            false,
            isInterface:        false,
            isReadonly:         false,
            dependencies:       ['App\\Infrastructure\\Clock'],
            implements:         ['App\\Contracts\\OrderService'],
            functionCalls:      ['var_dump'],
            superglobals:       ['$_SERVER'],
            languageConstructs: ['exit'],
        );

        $this->assertTrue($classNode->dependsOn('App\\Infrastructure'));
        $this->assertFalse($classNode->dependsOn('App\\Application'));
        $this->assertTrue($classNode->implementsInterface('App\\Contracts\\OrderService'));
        $this->assertTrue($classNode->callsFunction('var_dump'));
        $this->assertTrue($classNode->accessesSuperglobals());
        $this->assertTrue($classNode->usesLanguageConstruct('exit'));
        $this->assertFalse($classNode->usesLanguageConstruct('eval'));
    }

    public function testDependsOnMatchesExistingClassesExactly(): void
    {
        $classNode = new ClassNode(
            className:    'App\\Domain\\OrderService',
            file:         '/src/OrderService.php',
            line:         5,
            layer:        'Domain',
            extends:      null,
            isAbstract:   false,
            isFinal:      false,
            isInterface:  false,
            isReadonly:   false,
            dependencies: [DateTimeImmutable::class],
        );

        $this->assertTrue($classNode->dependsOn(DateTimeImmutable::class));
        $this->assertFalse($classNode->dependsOn(DateTime::class));
    }

    public function testDependsOnDoesNotTreatLoadedClassAsNamespacePrefix(): void
    {
        $classNode = new ClassNode(
            className:    'App\\Domain\\OrderService',
            file:         '/src/OrderService.php',
            line:         5,
            layer:        'Domain',
            extends:      null,
            isAbstract:   false,
            isFinal:      false,
            isInterface:  false,
            isReadonly:   false,
            dependencies: [self::class . '\\NestedDependency'],
        );

        $this->assertFalse($classNode->dependsOn(self::class));
    }

    public function testDependsOnDoesNotTreatLoadedPsr4ClassAsNamespacePrefix(): void
    {
        $classNode = new ClassNode(
            className:    'App\\Domain\\OrderService',
            file:         '/src/OrderService.php',
            line:         5,
            layer:        'Domain',
            extends:      null,
            isAbstract:   false,
            isFinal:      false,
            isInterface:  false,
            isReadonly:   false,
            dependencies: [ClassNode::class . '\\NestedDependency'],
        );

        // ClassNode is a PSR-4 class already loaded in memory, so class_exists($name, false)
        // returns true and dependsOn does not treat it as a namespace prefix
        $this->assertFalse($classNode->dependsOn(ClassNode::class));
    }

    public function testDependsOnTreatsUnloadedPsr4NamespaceAsNamespacePrefix(): void
    {
        $classNode = new ClassNode(
            className:    'App\\Domain\\OrderService',
            file:         '/src/OrderService.php',
            line:         5,
            layer:        'Domain',
            extends:      null,
            isAbstract:   false,
            isFinal:      false,
            isInterface:  false,
            isReadonly:   false,
            dependencies: ['App\\Bar\\SomeService'],
        );

        // App\Bar is not registered anywhere, so class_exists returns false even with autoloading,
        // and dependsOn treats it as a namespace prefix
        $this->assertTrue($classNode->dependsOn('App\\Bar'));
        $this->assertFalse($classNode->dependsOn('App\\Baz'));
    }

    public function testDependsOnDoesNotTreatLoadedAppFooClassAsNamespacePrefix(): void
    {
        $classNode = new ClassNode(
            className:    'App\\Domain\\OrderService',
            file:         '/src/OrderService.php',
            line:         5,
            layer:        'Domain',
            extends:      null,
            isAbstract:   false,
            isFinal:      false,
            isInterface:  false,
            isReadonly:   false,
            dependencies: ['App\\Foo\\SomeService'],
        );

        // App\Foo is registered via classmap; dependsOn triggers autoloading and treats it as a class
        $this->assertFalse($classNode->dependsOn(Foo::class));
    }

    public function testDependsOnMatchesNamespaceBoundaries(): void
    {
        $classNode = new ClassNode(
            className:    'App\\Domain\\OrderService',
            file:         '/src/OrderService.php',
            line:         5,
            layer:        'Domain',
            extends:      null,
            isAbstract:   false,
            isFinal:      false,
            isInterface:  false,
            isReadonly:   false,
            dependencies: ['Vendor\\PackageExtra\\Service'],
        );

        $this->assertTrue($classNode->dependsOn('Vendor\\PackageExtra'));
        $this->assertFalse($classNode->dependsOn('Vendor\\Package'));
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
