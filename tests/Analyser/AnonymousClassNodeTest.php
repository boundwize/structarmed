<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\AnonymousClassNode;
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
}
