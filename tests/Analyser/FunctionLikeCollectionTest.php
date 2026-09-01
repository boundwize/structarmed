<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\AnalysisNodeCollector;
use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;
use Boundwize\StructArmed\Analyser\FunctionLikeAnalysis;
use Boundwize\StructArmed\Analyser\FunctionNode;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use PhpParser\Node\Expr\Closure;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Collection of named functions, closures, and arrow functions by the
 * AnalysisNodeCollector into FunctionNodes and AnonymousFunctionNodes.
 */
#[CoversClass(AnonymousFunctionNode::class)]
#[CoversClass(AnalysisNodeCollector::class)]
#[CoversClass(FunctionLikeAnalysis::class)]
#[CoversClass(FunctionNode::class)]
final class FunctionLikeCollectionTest extends TestCase
{
    private const BASE_PATH = '/structarmed-test-project';

    private const FILE = self::BASE_PATH . '/src/Domain/helpers.php';

    private function makeCollector(string $code, string $file = self::FILE): AnalysisNodeCollector
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'src/Domain/'], self::BASE_PATH);
        $analysisNodeCollector  = new AnalysisNodeCollector($namespaceLayerResolver);
        $parser                 = (new ParserFactory())->createForNewestSupportedVersion();
        $ast                    = $parser->parse($code);

        $analysisNodeCollector->setCurrentFile($file);

        $nodeTraverser = new NodeTraverser(new NameResolver(), $analysisNodeCollector);
        $nodeTraverser->traverse($ast ?? []);

        return $analysisNodeCollector;
    }

    private function collectFunction(string $code): FunctionNode
    {
        $functionNodes = $this->makeCollector($code)->getFunctionNodes();
        $this->assertCount(1, $functionNodes, 'Expected exactly one function node');

        return $functionNodes[0];
    }

    private function collectAnonymousFunction(string $code): AnonymousFunctionNode
    {
        $anonymousFunctionNodes = $this->makeCollector($code)->getAnonymousFunctionNodes();
        $this->assertCount(1, $anonymousFunctionNodes, 'Expected exactly one anonymous function node');

        return $anonymousFunctionNodes[0];
    }

    public function testCollectsNamespacedFunctionWithLayerAndSignature(): void
    {
        $functionNode = $this->collectFunction(
            '<?php namespace App\Domain;' . "\n"
            . 'function format(int $amount, string $currency): string { return "x"; }'
        );

        $this->assertSame('App\Domain\format', $functionNode->functionName);
        $this->assertSame(self::FILE, $functionNode->file);
        $this->assertSame(2, $functionNode->line);
        $this->assertSame('Domain', $functionNode->layer);
        $this->assertSame(['Domain'], $functionNode->layers);
        $this->assertTrue($functionNode->hasReturnType);
        $this->assertSame(2, $functionNode->paramCount);
        $this->assertSame(1, $functionNode->cyclomaticComplexity);
        $this->assertSame(1, $functionNode->lineCount);
    }

    public function testCollectsGlobalFunctionOutsideAnyLayer(): void
    {
        $functionNode = $this->makeCollector(
            '<?php function helper() {}',
            self::BASE_PATH . '/bootstrap.php'
        )->getFunctionNodes()[0] ?? null;

        $this->assertInstanceOf(FunctionNode::class, $functionNode);
        $this->assertSame('helper', $functionNode->functionName);
        $this->assertNull($functionNode->layer);
        $this->assertSame([], $functionNode->layers);
        $this->assertFalse($functionNode->hasReturnType);
        $this->assertSame(0, $functionNode->paramCount);
        $this->assertSame(0, $functionNode->lineCount);
    }

    public function testSelectsMostSpecificLayerWhenMultipleLayersMatch(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            ['Source' => 'src/', 'Domain' => 'src/Domain/'],
            self::BASE_PATH
        );
        $analysisNodeCollector  = new AnalysisNodeCollector($namespaceLayerResolver);
        $parser                 = (new ParserFactory())->createForNewestSupportedVersion();
        $ast                    = $parser->parse('<?php namespace App\Domain; function helper(): void {}');

        $analysisNodeCollector->setCurrentFile(self::FILE);

        $nodeTraverser = new NodeTraverser(new NameResolver(), $analysisNodeCollector);
        $nodeTraverser->traverse($ast ?? []);

        $functionNode = $analysisNodeCollector->getFunctionNodes()[0];

        $this->assertSame('Domain', $functionNode->layer);
        $this->assertSame(['Source', 'Domain'], $functionNode->layers);
    }

    public function testCollectsFunctionDependenciesWithoutSeedingNamespaceImports(): void
    {
        $functionNode = $this->collectFunction(
            '<?php namespace App\Domain;' . "\n"
            . 'use App\Infrastructure\Mailer;' . "\n"
            . 'use Psr\Log\LoggerInterface;' . "\n"
            . 'function notify(Mailer $mailer, \DateTimeImmutable $at): void {'
            . ' $x = \App\Domain\Order::class; }'
        );

        // Unlike a class-like, the function does not inherit the unused
        // LoggerInterface import: a file's imports are not each function's.
        $this->assertSame(
            ['App\Infrastructure\Mailer', 'DateTimeImmutable', 'App\Domain\Order'],
            $functionNode->dependencies
        );
    }

    public function testFunctionReferencesStillCountAsFileReferences(): void
    {
        $analysisNodeCollector = $this->makeCollector(
            '<?php namespace App\Domain;' . "\n"
            . 'function check(object $value): bool { return $value instanceof Contract; }'
        );

        // Usage-aware rules keep relying on file references for procedural code.
        $this->assertSame([self::FILE => ['App\Domain\Contract']], $analysisNodeCollector->getFileReferences());
        $this->assertSame(['App\Domain\Contract'], $analysisNodeCollector->getFunctionNodes()[0]->dependencies);
    }

    public function testCollectsFunctionCallsSuperglobalsAndLanguageConstructs(): void
    {
        $functionNode = $this->makeCollector(
            '<?php namespace App\Domain;' . "\n"
            . 'function local(): void {}' . "\n"
            . 'function handle(): void { local(); strlen("x"); $id = $_GET["id"]; echo $id; exit(1); }'
        )->getFunctionNodes()[1];

        $this->assertSame('App\Domain\handle', $functionNode->functionName);
        $this->assertSame(['App\Domain\local', 'strlen'], $functionNode->functionCalls);
        $this->assertSame(['$_GET'], $functionNode->superglobals);
        $this->assertSame(['echo', 'exit'], $functionNode->languageConstructs);
        $this->assertTrue($functionNode->callsFunction('App\Domain\local'));
        $this->assertTrue($functionNode->accessesSuperglobals());
        $this->assertTrue($functionNode->usesLanguageConstruct('die'));
    }

    public function testResolvesCallToFunctionDeclaredLaterInFile(): void
    {
        $functionNode = $this->makeCollector(
            '<?php namespace App\Domain;' . "\n"
            . 'function caller(): void { callee(); }' . "\n"
            . 'function callee(): void {}'
        )->getFunctionNodes()[0];

        // Function nodes are built after the whole file is traversed, so a
        // call to a function declared further down still resolves.
        $this->assertSame('App\Domain\caller', $functionNode->functionName);
        $this->assertSame(['App\Domain\callee'], $functionNode->functionCalls);
    }

    public function testCalculatesFunctionCyclomaticComplexityAndLineCount(): void
    {
        $functionNode = $this->collectFunction(
            '<?php namespace App\Domain;' . "\n"
            . 'function score(int $n): int {' . "\n"
            . '    if ($n > 1 && $n < 10) {' . "\n"
            . '        return 1;' . "\n"
            . '    }' . "\n"
            . '    foreach ([1, 2] as $item) {' . "\n"
            . '        $n += $item ?? 0;' . "\n"
            . '    }' . "\n"
            . '    return $n;' . "\n"
            . '}'
        );

        // 1 + if + && + foreach + ??
        $this->assertSame(5, $functionNode->cyclomaticComplexity);
        $this->assertSame(7, $functionNode->lineCount);
    }

    public function testFunctionNodesAreCollectedAfterClassNodesInSourceOrder(): void
    {
        $analysisNodeCollector = $this->makeCollector(
            '<?php namespace App\Domain;' . "\n"
            . 'function first(): void {}' . "\n"
            . 'final class Foo {}' . "\n"
            . 'function second(): void {}'
        );

        $this->assertSame(['App\Domain\Foo'], [$analysisNodeCollector->getClassNodes()[0]->className]);
        $this->assertSame(
            ['App\Domain\first', 'App\Domain\second'],
            [
                $analysisNodeCollector->getFunctionNodes()[0]->functionName,
                $analysisNodeCollector->getFunctionNodes()[1]->functionName,
            ]
        );
    }

    public function testCollectsTopLevelClosure(): void
    {
        $anonymousFunctionNode = $this->collectAnonymousFunction(
            '<?php namespace App\Domain;' . "\n"
            . '$fn = function (int $a, int $b): int { return $a + $b; };'
        );

        $this->assertSame(self::FILE, $anonymousFunctionNode->file);
        $this->assertSame(2, $anonymousFunctionNode->line);
        $this->assertSame('Domain', $anonymousFunctionNode->layer);
        $this->assertFalse($anonymousFunctionNode->isArrowFunction);
        $this->assertFalse($anonymousFunctionNode->isStatic);
        $this->assertNull($anonymousFunctionNode->enclosingClassName);
        $this->assertNull($anonymousFunctionNode->enclosingFunctionName);
        $this->assertTrue($anonymousFunctionNode->hasReturnType);
        $this->assertSame(2, $anonymousFunctionNode->paramCount);
        $this->assertSame(1, $anonymousFunctionNode->cyclomaticComplexity);
        $this->assertSame(1, $anonymousFunctionNode->lineCount);
    }

    public function testCollectsStaticArrowFunction(): void
    {
        $anonymousFunctionNode = $this->collectAnonymousFunction(
            '<?php namespace App\Domain;' . "\n"
            . '$fn = static fn (int $a) => $a > 1 ? $a : 0;'
        );

        $this->assertTrue($anonymousFunctionNode->isArrowFunction);
        $this->assertTrue($anonymousFunctionNode->isStatic);
        $this->assertFalse($anonymousFunctionNode->hasReturnType);
        $this->assertSame(1, $anonymousFunctionNode->paramCount);
        $this->assertSame(2, $anonymousFunctionNode->cyclomaticComplexity);
        $this->assertSame(1, $anonymousFunctionNode->lineCount);
        $this->assertSame('Arrow function', $anonymousFunctionNode->getType());
    }

    public function testRecordsEnclosingClassAndCountsClosureBodyOnBothNodes(): void
    {
        $analysisNodeCollector = $this->makeCollector(
            '<?php namespace App\Domain;' . "\n"
            . 'use App\Infrastructure\Mailer;' . "\n"
            . 'final class Handler {' . "\n"
            . '    public function handle(): void {' . "\n"
            . '        $send = function () { $m = new \App\Infrastructure\Mailer(); strlen("x"); exit; };' . "\n"
            . '    }' . "\n"
            . '}'
        );

        $anonymousFunctionNode = $analysisNodeCollector->getAnonymousFunctionNodes()[0];
        $classNode             = $analysisNodeCollector->getClassNodes()[0];

        $this->assertSame('App\Domain\Handler', $anonymousFunctionNode->enclosingClassName);
        $this->assertNull($anonymousFunctionNode->enclosingFunctionName);
        $this->assertSame('App\Domain\Handler', $anonymousFunctionNode->enclosingScopeName());
        $this->assertSame(5, $anonymousFunctionNode->line);

        // Neither function-like inherits the namespace imports; the class does.
        $this->assertSame(['App\Infrastructure\Mailer'], $anonymousFunctionNode->dependencies);
        $this->assertSame(['App\Infrastructure\Mailer'], $classNode->dependencies);

        $this->assertSame(['strlen'], $anonymousFunctionNode->functionCalls);
        $this->assertSame(['strlen'], $classNode->functionCalls);
        $this->assertSame(['exit'], $anonymousFunctionNode->languageConstructs);
        $this->assertSame(['exit'], $classNode->languageConstructs);
    }

    public function testRecordsEnclosingFunctionForClosureInsideNamedFunction(): void
    {
        $analysisNodeCollector = $this->makeCollector(
            '<?php namespace App\Domain;' . "\n"
            . 'function build(): callable { return fn () => $_POST["x"] ?? null; }'
        );

        $anonymousFunctionNode = $analysisNodeCollector->getAnonymousFunctionNodes()[0];
        $functionNode          = $analysisNodeCollector->getFunctionNodes()[0];

        $this->assertNull($anonymousFunctionNode->enclosingClassName);
        $this->assertSame('App\Domain\build', $anonymousFunctionNode->enclosingFunctionName);
        $this->assertSame('App\Domain\build', $anonymousFunctionNode->enclosingScopeName());
        $this->assertSame(['$_POST'], $anonymousFunctionNode->superglobals);
        $this->assertSame(2, $anonymousFunctionNode->cyclomaticComplexity);

        // The enclosing function sees the closure body too.
        $this->assertSame(['$_POST'], $functionNode->superglobals);
        $this->assertSame(2, $functionNode->cyclomaticComplexity);
    }

    public function testNestedClosuresEachGetTheirOwnNodeAndComplexity(): void
    {
        $anonymousFunctionNodes = $this->makeCollector(
            '<?php namespace App\Domain;' . "\n"
            . '$outer = function () {' . "\n"
            . '    $inner = function () { if (true) { return 1; } };' . "\n"
            . '    return $inner;' . "\n"
            . '};'
        )->getAnonymousFunctionNodes();

        $this->assertCount(2, $anonymousFunctionNodes);
        // Source order: the outer closure is entered first.
        $this->assertSame(2, $anonymousFunctionNodes[0]->line);
        $this->assertSame(2, $anonymousFunctionNodes[0]->cyclomaticComplexity);
        $this->assertSame(3, $anonymousFunctionNodes[1]->line);
        $this->assertSame(2, $anonymousFunctionNodes[1]->cyclomaticComplexity);
    }

    public function testClosureInsideAnonymousClassResolvesToTheNamedEnclosingClass(): void
    {
        $anonymousFunctionNode = $this->collectAnonymousFunction(
            '<?php namespace App\Domain;' . "\n"
            . 'final class Factory {' . "\n"
            . '    public function make(): object {' . "\n"
            . '        return new class { public function run(): callable { return fn () => 1; } };' . "\n"
            . '    }' . "\n"
            . '}'
        );

        $this->assertSame('App\Domain\Factory', $anonymousFunctionNode->enclosingClassName);
    }

    public function testClosureInsideTopLevelAnonymousClassHasNoEnclosingScope(): void
    {
        $anonymousFunctionNode = $this->collectAnonymousFunction(
            '<?php namespace App\Domain;' . "\n"
            . 'return new class { public function run(): callable { return fn () => 1; } };'
        );

        $this->assertNull($anonymousFunctionNode->enclosingClassName);
        $this->assertNull($anonymousFunctionNode->enclosingFunctionName);
        $this->assertSame('file scope', $anonymousFunctionNode->enclosingScopeName());
    }

    public function testTracksThisUsageThroughNestedClosuresButNotAcrossAnonymousClasses(): void
    {
        $anonymousFunctionNodes = $this->makeCollector(
            '<?php namespace App\Domain;' . "\n"
            . 'final class Handler {' . "\n"
            . '    public function handle(): void {' . "\n"
            . '        $plain = fn () => 1;' . "\n"
            . '        $outer = function () { return function () { return $this->x; }; };' . "\n"
            . '        $anon  = function () { return new class { public function run() { return $this; } }; };' . "\n"
            . '        $static = static fn () => 2;' . "\n"
            . '    }' . "\n"
            . '}'
        )->getAnonymousFunctionNodes();

        $this->assertCount(5, $anonymousFunctionNodes);
        $this->assertFalse($anonymousFunctionNodes[0]->usesThis, 'plain arrow function');
        $this->assertTrue($anonymousFunctionNodes[1]->usesThis, 'outer closure captures $this for the inner one');
        $this->assertTrue($anonymousFunctionNodes[2]->usesThis, 'inner closure reads $this');
        $this->assertFalse($anonymousFunctionNodes[3]->usesThis, '$this inside the anonymous class is its own');
        $this->assertFalse($anonymousFunctionNodes[4]->usesThis, 'static arrow function');
        $this->assertTrue($anonymousFunctionNodes[4]->isStatic);
    }

    public function testIgnoresVariableVariablesWhenTrackingThisAndSuperglobals(): void
    {
        $anonymousFunctionNode = $this->collectAnonymousFunction(
            '<?php $name = "this"; $fn = function () use ($name) { return $$name; };'
        );

        $this->assertFalse($anonymousFunctionNode->usesThis);
        $this->assertFalse($anonymousFunctionNode->accessesSuperglobals());
    }

    public function testTracksThisUsageInTopLevelClosure(): void
    {
        $anonymousFunctionNode = $this->collectAnonymousFunction(
            '<?php $bound = function () { return $this->value; };'
        );

        $this->assertTrue($anonymousFunctionNode->usesThis);
    }

    public function testIgnoresFunctionLikeExitWithoutMatchingEntry(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'src/Domain/'], self::BASE_PATH);
        $analysisNodeCollector  = new AnalysisNodeCollector($namespaceLayerResolver);

        $analysisNodeCollector->leaveNode(new Closure());

        $this->assertSame([], $analysisNodeCollector->getAnonymousFunctionNodes());
    }

    public function testMethodComplexityStillAggregatesNestedClosureBranches(): void
    {
        $classNode = $this->makeCollector(
            '<?php namespace App\Domain;' . "\n"
            . 'final class Handler { public function handle(): void {'
            . ' $fn = function () { if (true) { return; } }; if (false) { return; } } }'
        )->getClassNodes()[0];

        $this->assertSame(3, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testResetsFunctionLikeStateBetweenFiles(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'src/Domain/'], self::BASE_PATH);
        $analysisNodeCollector  = new AnalysisNodeCollector($namespaceLayerResolver);
        $parser                 = (new ParserFactory())->createForNewestSupportedVersion();
        $nodeTraverser          = new NodeTraverser(new NameResolver(), $analysisNodeCollector);

        $analysisNodeCollector->setCurrentFile(self::BASE_PATH . '/src/Domain/a.php');
        $nodeTraverser->traverse($parser->parse('<?php function a() { $f = fn () => 1; }') ?? []);

        $analysisNodeCollector->setCurrentFile(self::BASE_PATH . '/src/Domain/b.php');
        $nodeTraverser->traverse($parser->parse('<?php function b() {}') ?? []);

        $functionNodes = $analysisNodeCollector->getFunctionNodes();

        $this->assertCount(2, $functionNodes);
        $this->assertSame(self::BASE_PATH . '/src/Domain/a.php', $functionNodes[0]->file);
        $this->assertSame(self::BASE_PATH . '/src/Domain/b.php', $functionNodes[1]->file);
        $this->assertCount(1, $analysisNodeCollector->getAnonymousFunctionNodes());
    }
}
