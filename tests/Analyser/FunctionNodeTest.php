<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\FunctionNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FunctionNode::class)]
final class FunctionNodeTest extends TestCase
{
    public function testNameHelpersAndLayerChecks(): void
    {
        $functionNode = new FunctionNode(
            functionName: 'App\\Support\\format_money',
            file:         '/src/helpers.php',
            line:         12,
            layer:        'Support',
        );

        $this->assertSame('format_money', $functionNode->shortName());
        $this->assertSame(['Support'], $functionNode->layers);
        $this->assertTrue($functionNode->isInLayer('Support'));
        $this->assertFalse($functionNode->isInLayer('Domain'));
        $this->assertTrue($functionNode->nameStartsWith('format_'));
        $this->assertTrue($functionNode->nameEndsWith('_money'));
        $this->assertTrue($functionNode->nameMatches('/^format_/'));
        $this->assertFalse($functionNode->nameMatches('/^App\\\\Support\\\\format_money$/'));
        $this->assertTrue($functionNode->nameMatches('/^App\\\\Support\\\\format_money$/', isFullName: true));
    }

    public function testGlobalFunctionShortNameIsItsName(): void
    {
        $functionNode = new FunctionNode(functionName: 'helper', file: '/src/helpers.php', line: 1, layer: null);

        $this->assertSame('helper', $functionNode->shortName());
        $this->assertSame([], $functionNode->layers);
    }

    public function testExplicitLayersOverrideSingleLayer(): void
    {
        $functionNode = new FunctionNode(
            functionName: 'helper',
            file:         '/src/helpers.php',
            line:         1,
            layer:        'Support',
            layers:       ['Support', 'Source'],
        );

        $this->assertTrue($functionNode->isInLayer('Source'));
    }

    public function testBodyQueries(): void
    {
        $functionNode = new FunctionNode(
            functionName:       'App\\render',
            file:               '/src/helpers.php',
            line:               1,
            layer:              'Support',
            dependencies:       ['App\\View\\Template', 'Psr\\Log\\LoggerInterface'],
            functionCalls:      ['App\\escape', 'sprintf'],
            superglobals:       ['$_GET'],
            languageConstructs: ['die'],
        );

        $this->assertTrue($functionNode->dependsOn('App\\View\\Template'));
        $this->assertFalse($functionNode->dependsOn('App\\View\\Renderer'));
        $this->assertTrue($functionNode->dependsOnNamespace('Psr\\Log'));
        $this->assertTrue($functionNode->dependsOnNamespace('Psr\\Log\\'));
        $this->assertFalse($functionNode->dependsOnNamespace('Psr\\Http'));
        $this->assertTrue($functionNode->callsFunction('SPRINTF'));
        $this->assertFalse($functionNode->callsFunction('printf'));
        $this->assertTrue($functionNode->accessesSuperglobals());
        $this->assertTrue($functionNode->usesLanguageConstruct('die'));
        $this->assertTrue($functionNode->usesLanguageConstruct('exit'));
        $this->assertFalse($functionNode->usesLanguageConstruct('echo'));
    }

    public function testExitAliasesDieAndNothingElse(): void
    {
        $functionNode = new FunctionNode(
            functionName:       'stop',
            file:               '/src/helpers.php',
            line:               1,
            layer:              null,
            languageConstructs: ['exit'],
        );

        $this->assertTrue($functionNode->usesLanguageConstruct('die'));
        $this->assertFalse($functionNode->usesLanguageConstruct('eval'));
        $this->assertFalse($functionNode->accessesSuperglobals());
    }
}
