<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\AnonymousClassNode;
use Boundwize\StructArmed\Analyser\ConstantNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Analyser\PropertyNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnonymousClassNode::class)]
final class AnonymousClassNodeTest extends TestCase
{
    public function testParentChainQueriesAreCaseInsensitive(): void
    {
        $anonymousClassNode = new AnonymousClassNode(
            file:       '/src/HandlerFactory.php',
            line:       7,
            extends:    'App\\Support\\baseclass',
            implements: ['App\\Contracts\\foointerface'],
        );

        $this->assertSame([], $anonymousClassNode->parentClasses);
        $this->assertSame([], $anonymousClassNode->parentInterfaces);
        $this->assertTrue($anonymousClassNode->extendsClass('App\\Support\\BaseClass'));
        $this->assertFalse($anonymousClassNode->extendsClass('App\\Support\\RootClass'));
        $this->assertTrue($anonymousClassNode->implementsInterface('App\\Contracts\\FooInterface'));
        $this->assertFalse($anonymousClassNode->implementsInterface('App\\Contracts\\RootInterface'));

        $anonymousClassNode->setRecursiveParents(
            ['App\\Support\\baseclass', 'App\\Support\\rootclass'],
            ['App\\Contracts\\foointerface', 'App\\Contracts\\rootinterface'],
        );

        $this->assertSame(['App\\Support\\baseclass', 'App\\Support\\rootclass'], $anonymousClassNode->parentClasses);
        $this->assertTrue($anonymousClassNode->extendsClass('App\\Support\\RootClass'));
        $this->assertFalse($anonymousClassNode->extendsClass('App\\Support\\OtherClass'));
        $this->assertTrue($anonymousClassNode->implementsInterface('App\\Contracts\\RootInterface'));
        $this->assertFalse($anonymousClassNode->implementsInterface('App\\Contracts\\OtherInterface'));
    }

    public function testExtendsClassWithoutParentIsAlwaysFalse(): void
    {
        $anonymousClassNode = new AnonymousClassNode(file: '/src/helpers.php', line: 3, extends: null);

        $this->assertFalse($anonymousClassNode->extendsClass('App\\Support\\BaseClass'));
        $this->assertFalse($anonymousClassNode->implementsInterface('App\\Contracts\\FooInterface'));
    }

    public function testCarriesMembersAndBodyFactsLikeAClassNode(): void
    {
        $anonymousClassNode = new AnonymousClassNode(
            file:               '/src/HandlerFactory.php',
            line:               7,
            extends:            null,
            layer:              'Source',
            isReadonly:         true,
            dependencies:       ['App\\Support\\Clock'],
            methods:            [new MethodNode('__construct', 'public', false, false, 2, 1, 3)],
            constants:          [new ConstantNode('LIMIT')],
            properties:         [new PropertyNode('clock', 'private', true)],
            functionCalls:      ['strlen'],
            superglobals:       ['$_GET'],
            languageConstructs: ['die'],
        );

        $this->assertTrue($anonymousClassNode->isReadonly);
        $this->assertTrue($anonymousClassNode->isInLayer('Source'));
        $this->assertTrue($anonymousClassNode->dependsOn('App\\Support\\Clock'));
        $this->assertTrue($anonymousClassNode->dependsOnNamespace('App\\Support'));
        $this->assertTrue($anonymousClassNode->callsFunction('STRLEN'));
        $this->assertTrue($anonymousClassNode->accessesSuperglobals());
        $this->assertTrue($anonymousClassNode->usesLanguageConstruct('exit'));
        $this->assertSame(2, $anonymousClassNode->constructorParamCount());
        $this->assertSame('LIMIT', $anonymousClassNode->constants[0]->name);
        $this->assertSame('clock', $anonymousClassNode->properties[0]->name);
    }

    public function testMembersAndBodyFactsDefaultToEmpty(): void
    {
        $anonymousClassNode = new AnonymousClassNode(file: '/src/helpers.php', line: 3, extends: null);

        $this->assertFalse($anonymousClassNode->isReadonly);
        $this->assertSame([], $anonymousClassNode->methods);
        $this->assertSame([], $anonymousClassNode->constants);
        $this->assertSame([], $anonymousClassNode->properties);
        $this->assertSame(0, $anonymousClassNode->constructorParamCount());
        $this->assertFalse($anonymousClassNode->dependsOn('App\\Support\\Clock'));
        $this->assertFalse($anonymousClassNode->callsFunction('strlen'));
        $this->assertFalse($anonymousClassNode->accessesSuperglobals());
        $this->assertFalse($anonymousClassNode->usesLanguageConstruct('exit'));
    }
}
