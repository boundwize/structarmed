<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\ClassCollector;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function getcwd;

#[CoversClass(ClassCollector::class)]
final class ClassCollectorTest extends TestCase
{
    private function collect(string $code, bool $resolveNames = false): ClassNode
    {
        $nodes = $this->collectNodes($code, $resolveNames);
        $this->assertNotEmpty($nodes, 'No class nodes collected');

        return $nodes[0];
    }

    /** @return ClassNode[] */
    private function collectNodes(string $code, bool $resolveNames = false): array
    {
        $cwd                    = getcwd();
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'src/Domain/'], $cwd !== false ? $cwd : '');
        $classCollector         = new ClassCollector($namespaceLayerResolver);
        $parser                 = (new ParserFactory())->createForNewestSupportedVersion();
        $ast                    = $parser->parse($code);

        $classCollector->setCurrentFile('/fake/path/Foo.php');

        $nodeTraverser = new NodeTraverser();

        if ($resolveNames) {
            $nodeTraverser->addVisitor(new NameResolver());
        }

        $nodeTraverser->addVisitor($classCollector);
        $nodeTraverser->traverse($ast ?? []);

        return $classCollector->getNodes();
    }

    public function testCollectsFinalClass(): void
    {
        $classNode = $this->collect('<?php final class Foo {}');

        $this->assertTrue($classNode->isFinal);
        $this->assertFalse($classNode->isAbstract);
        $this->assertFalse($classNode->isInterface);
    }

    public function testCollectsAbstractClass(): void
    {
        $classNode = $this->collect('<?php abstract class Foo {}');

        $this->assertTrue($classNode->isAbstract);
        $this->assertFalse($classNode->isFinal);
    }

    public function testCollectsInterface(): void
    {
        $classNode = $this->collect('<?php interface FooInterface {}');

        $this->assertTrue($classNode->isInterface);
    }

    public function testCollectsTrait(): void
    {
        $classNode = $this->collect('<?php trait FooTrait {}');

        $this->assertSame('FooTrait', $classNode->className);
        $this->assertFalse($classNode->isInterface);
        $this->assertTrue($classNode->isTrait);
    }

    public function testCollectsTraitWithPsr4Namespace(): void
    {
        $classNode = $this->collect('<?php namespace App\Domain; trait FooTrait {}', resolveNames: true);

        $this->assertSame('App\Domain\FooTrait', $classNode->className);
        $this->assertTrue($classNode->isTrait);
        $this->assertFalse($classNode->isInterface);
    }

    public function testCollectsEnum(): void
    {
        $classNode = $this->collect('<?php enum Status: string implements Stringable { case Draft = "draft"; }');

        $this->assertSame('Status', $classNode->className);
        $this->assertSame(['Stringable'], $classNode->implements);
        $this->assertFalse($classNode->isInterface);
    }

    public function testIgnoresAnonymousClasses(): void
    {
        $nodes = $this->collectNodes('<?php $foo = new class {};');

        $this->assertSame([], $nodes);
    }

    public function testCollectsExtendedClassAndImplementedInterfaces(): void
    {
        $classNode = $this->collect('<?php class Foo extends BaseFoo implements First, Second {}');

        $this->assertSame('BaseFoo', $classNode->extends);
        $this->assertSame(['First', 'Second'], $classNode->implements);
    }

    public function testCollectsUsedTraits(): void
    {
        $classNode = $this->collect('<?php class Foo { use FirstTrait, SecondTrait; }');

        $this->assertSame(['FirstTrait', 'SecondTrait'], $classNode->traits);
    }

    public function testCollectsMethodReturnType(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): string { return "x"; } }');

        $this->assertCount(1, $classNode->methods);
        $this->assertTrue($classNode->methods[0]->hasReturnType);
        $this->assertSame('bar', $classNode->methods[0]->name);
    }

    public function testCollectsClassConstants(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public const VERSION = "1.0"; private const dateApproved = "x"; }'
        );

        $this->assertCount(2, $classNode->constants);
        $this->assertSame('VERSION', $classNode->constants[0]->name);
        $this->assertSame('dateApproved', $classNode->constants[1]->name);
    }

    public function testCollectsProperties(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public string $name; private int $count = 0; }'
        );

        $this->assertCount(2, $classNode->properties);
        $this->assertSame('name', $classNode->properties[0]->name);
        $this->assertSame('public', $classNode->properties[0]->visibility);
        $this->assertTrue($classNode->properties[0]->hasExplicitVisibility);
        $this->assertSame('count', $classNode->properties[1]->name);
        $this->assertSame('private', $classNode->properties[1]->visibility);
        $this->assertTrue($classNode->properties[1]->hasExplicitVisibility);
    }

    public function testDetectsImplicitPropertyVisibility(): void
    {
        $classNode = $this->collect('<?php class Foo { var $legacy; }');

        $this->assertCount(1, $classNode->properties);
        $this->assertSame('public', $classNode->properties[0]->visibility);
        $this->assertFalse($classNode->properties[0]->hasExplicitVisibility);
    }

    public function testCollectsPromotedProperties(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public function __construct(private string $name, public readonly int $count) {} }'
        );

        $this->assertCount(2, $classNode->properties);
        $this->assertSame('name', $classNode->properties[0]->name);
        $this->assertSame('private', $classNode->properties[0]->visibility);
        $this->assertTrue($classNode->properties[0]->hasExplicitVisibility);
        $this->assertSame('count', $classNode->properties[1]->name);
        $this->assertSame('public', $classNode->properties[1]->visibility);
        $this->assertTrue($classNode->properties[1]->hasExplicitVisibility);
    }

    public function testDoesNotCollectNonPromotedConstructorParams(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public function __construct(string $name) {} }'
        );

        $this->assertCount(0, $classNode->properties);
    }

    public function testCollectsMixedTraditionalAndPromotedProperties(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public string $title; public function __construct(private int $id) {} }'
        );

        $this->assertCount(2, $classNode->properties);
        $this->assertSame('title', $classNode->properties[0]->name);
        $this->assertSame('id', $classNode->properties[1]->name);
        $this->assertSame('private', $classNode->properties[1]->visibility);
    }

    public function testCollectsProtectedAndPrivateMethodVisibility(): void
    {
        $classNode = $this->collect(
            <<<CODE
            <?php class Foo {
                protected function one(): void {}
                private function two(): void {}
            }
            CODE
        );

        $this->assertSame('protected', $classNode->methods[0]->visibility);
        $this->assertSame('private', $classNode->methods[1]->visibility);
    }

    public function testDetectsMissingReturnType(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar() { return "x"; } }');

        $this->assertCount(1, $classNode->methods);
        $this->assertFalse($classNode->methods[0]->hasReturnType);
    }

    public function testCollectsFunctionCalls(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { var_dump("x"); } }');

        $this->assertContains('var_dump', $classNode->functionCalls);
    }

    public function testCollectsImportedFunctionCalls(): void
    {
        $code      = <<<'PHP'
<?php
namespace App\Support;

use function Vendor\debug;

class Foo {
    public function bar(): void {
        debug("x");
    }
}
PHP;
        $classNode = $this->collect($code, resolveNames: true);

        $this->assertContains('Vendor\debug', $classNode->functionCalls);
    }

    public function testKeepsNativeFunctionCallsUnqualifiedInsideNamespace(): void
    {
        $classNode = $this->collect(
            '<?php namespace App\Support; class Foo { public function bar(): int { return strlen("x"); } }',
            resolveNames: true
        );

        $this->assertContains('strlen', $classNode->functionCalls);
        $this->assertNotContains('App\Support\strlen', $classNode->functionCalls);
    }

    public function testCollectsDeclaredNamespacedFunctionCalls(): void
    {
        $code      = <<<'PHP'
<?php
namespace App\Support;

class Foo {
    public function bar(): void {
        debug("x");
    }
}

function debug(string $value): void {}
PHP;
        $classNode = $this->collect($code, resolveNames: true);

        $this->assertContains('App\Support\debug', $classNode->functionCalls);
    }

    public function testKeepsUnresolvedFunctionCallsAsWrittenInsideNamespace(): void
    {
        $classNode = $this->collect(
            '<?php namespace App\Support; class Foo { public function bar(): void { missing_function("x"); } }',
            resolveNames: true
        );

        $this->assertContains('missing_function', $classNode->functionCalls);
        $this->assertNotContains('App\Support\missing_function', $classNode->functionCalls);
    }

    public function testCollectsSuperglobals(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { $x = $_GET["id"]; } }');

        $this->assertContains('$_GET', $classNode->superglobals);
    }

    public function testCalculatesCyclomaticComplexity(): void
    {
        $code      = <<<'PHP'
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
        $classNode = $this->collect($code);

        // Base 1 + if + elseif = 3
        $this->assertGreaterThanOrEqual(3, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testCollectsDependencies(): void
    {
        $code      = <<<'PHP'
<?php
use DateTime;
use App\Domain\Order;

class Foo {}
PHP;
        $classNode = $this->collect($code);

        $this->assertContains('DateTime', $classNode->dependencies);
        $this->assertContains('App\Domain\Order', $classNode->dependencies);
    }

    public function testCollectsFullyQualifiedDependencies(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { new \DateTimeImmutable(); } }');

        $this->assertContains('DateTimeImmutable', $classNode->dependencies);
    }

    public function testShortNameExtraction(): void
    {
        $classNode = $this->collect('<?php namespace App\Domain; final class OrderEntity {}', resolveNames: true);

        $this->assertSame('App\Domain\OrderEntity', $classNode->className);
        $this->assertSame('OrderEntity', $classNode->shortName());
    }

    public function testDoesNotCollectSpecialNamesAsDependencies(): void
    {
        $classNode = $this->collect(
            <<<'PHP'
            <?php
            class Foo {
                public function bar(): void {
                    $a = \true;
                    $b = \false;
                    $c = \null;
                    new \DateTimeImmutable();
                }
            }
            PHP
        );

        $this->assertNotContains('true', $classNode->dependencies);
        $this->assertNotContains('false', $classNode->dependencies);
        $this->assertNotContains('null', $classNode->dependencies);
        $this->assertContains('DateTimeImmutable', $classNode->dependencies);
    }
}
