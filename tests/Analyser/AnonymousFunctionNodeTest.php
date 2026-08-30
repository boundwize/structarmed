<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnonymousFunctionNode::class)]
final class AnonymousFunctionNodeTest extends TestCase
{
    public function testClosureDefaults(): void
    {
        $anonymousFunctionNode = new AnonymousFunctionNode(file: '/src/helpers.php', line: 3, layer: 'Support');

        $this->assertSame('Closure', $anonymousFunctionNode->getType());
        $this->assertFalse($anonymousFunctionNode->isArrowFunction);
        $this->assertFalse($anonymousFunctionNode->isStatic);
        $this->assertSame('file scope', $anonymousFunctionNode->enclosingScopeName());
        $this->assertSame(AnonymousFunctionNode::FILE_SCOPE, $anonymousFunctionNode->enclosingScopeName());
        $this->assertSame(['Support'], $anonymousFunctionNode->layers);
        $this->assertTrue($anonymousFunctionNode->isInLayer('Support'));
        $this->assertFalse($anonymousFunctionNode->accessesSuperglobals());
    }

    public function testEnclosingClassWinsOverEnclosingFunction(): void
    {
        $anonymousFunctionNode = new AnonymousFunctionNode(
            file:                  '/src/Handler.php',
            line:                  9,
            layer:                 null,
            isArrowFunction:       true,
            isStatic:              true,
            enclosingClassName:    'App\\Handler',
            enclosingFunctionName: 'App\\bootstrap',
        );

        $this->assertSame('Arrow function', $anonymousFunctionNode->getType());
        $this->assertSame('App\\Handler', $anonymousFunctionNode->enclosingScopeName());
        $this->assertSame([], $anonymousFunctionNode->layers);
    }

    public function testEnclosingFunctionIsUsedWithoutEnclosingClass(): void
    {
        $anonymousFunctionNode = new AnonymousFunctionNode(
            file:                  '/src/helpers.php',
            line:                  9,
            layer:                 null,
            enclosingFunctionName: 'App\\bootstrap',
        );

        $this->assertSame('App\\bootstrap', $anonymousFunctionNode->enclosingScopeName());
    }

    public function testBodyQueries(): void
    {
        $anonymousFunctionNode = new AnonymousFunctionNode(
            file:               '/src/helpers.php',
            line:               1,
            layer:              'Support',
            dependencies:       ['App\\View\\Template'],
            functionCalls:      ['App\\escape'],
            superglobals:       ['$_POST'],
            languageConstructs: ['exit'],
        );

        $this->assertTrue($anonymousFunctionNode->dependsOn('App\\View\\Template'));
        $this->assertTrue($anonymousFunctionNode->dependsOnNamespace('App\\View'));
        $this->assertFalse($anonymousFunctionNode->dependsOnNamespace('App\\Domain'));
        $this->assertTrue($anonymousFunctionNode->callsFunction('app\\escape'));
        $this->assertTrue($anonymousFunctionNode->accessesSuperglobals());
        $this->assertTrue($anonymousFunctionNode->usesLanguageConstruct('exit'));
        $this->assertTrue($anonymousFunctionNode->usesLanguageConstruct('die'));
        $this->assertFalse($anonymousFunctionNode->usesLanguageConstruct('print'));
    }
}
