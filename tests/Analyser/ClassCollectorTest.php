<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\ClassCollector;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassCollector::class)]
final class ClassCollectorTest extends TestCase
{
    private function collect(string $code): \Boundwize\StructArmed\Analyser\ClassNode
    {
        $resolver  = new NamespaceLayerResolver(['Domain' => 'src/Domain/'], getcwd());
        $collector = new ClassCollector($resolver);
        $parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $ast       = $parser->parse($code);

        $collector->setCurrentFile('/fake/path/Foo.php');

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($ast ?? []);

        $nodes = $collector->getNodes();
        $this->assertNotEmpty($nodes, 'No class nodes collected');

        return $nodes[0];
    }

    public function testCollectsFinalClass(): void
    {
        $node = $this->collect('<?php final class Foo {}');

        $this->assertTrue($node->isFinal);
        $this->assertFalse($node->isAbstract);
        $this->assertFalse($node->isInterface);
    }

    public function testCollectsAbstractClass(): void
    {
        $node = $this->collect('<?php abstract class Foo {}');

        $this->assertTrue($node->isAbstract);
        $this->assertFalse($node->isFinal);
    }

    public function testCollectsInterface(): void
    {
        $node = $this->collect('<?php interface FooInterface {}');

        $this->assertTrue($node->isInterface);
    }

    public function testCollectsMethodReturnType(): void
    {
        $node = $this->collect('<?php class Foo { public function bar(): string { return "x"; } }');

        $this->assertCount(1, $node->methods);
        $this->assertTrue($node->methods[0]->hasReturnType);
        $this->assertSame('bar', $node->methods[0]->name);
    }

    public function testDetectsMissingReturnType(): void
    {
        $node = $this->collect('<?php class Foo { public function bar() { return "x"; } }');

        $this->assertCount(1, $node->methods);
        $this->assertFalse($node->methods[0]->hasReturnType);
    }

    public function testCollectsFunctionCalls(): void
    {
        $node = $this->collect('<?php class Foo { public function bar(): void { var_dump("x"); } }');

        $this->assertContains('var_dump', $node->functionCalls);
    }

    public function testCollectsSuperglobals(): void
    {
        $node = $this->collect('<?php class Foo { public function bar(): void { $x = $_GET["id"]; } }');

        $this->assertContains('$_GET', $node->superglobals);
    }

    public function testCalculatesCyclomaticComplexity(): void
    {
        $code = <<<'PHP'
<?php
class Foo {
    public function bar(int $x): string {
        if ($x > 0) {
            return "positive";
        } elseif ($x < 0) {
            return "negative";
        } else {
            return "zero";
        }
    }
}
PHP;
        $node = $this->collect($code);

        // Base 1 + if + elseif = 3
        $this->assertGreaterThanOrEqual(3, $node->methods[0]->cyclomaticComplexity);
    }

    public function testCollectsDependencies(): void
    {
        $code = <<<'PHP'
<?php
use DateTime;
use App\Domain\Order;

class Foo {}
PHP;
        $node = $this->collect($code);

        $this->assertContains('DateTime', $node->dependencies);
        $this->assertContains('App\Domain\Order', $node->dependencies);
    }

    public function testShortNameExtraction(): void
    {
        $node = $this->collect('<?php namespace App\Domain; final class OrderEntity {}');

        $this->assertSame('OrderEntity', $node->shortName());
    }
}
