<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\ClassCollector;
use Boundwize\StructArmed\Analyser\ClassLikeAnalysis;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;

#[CoversClass(ClassCollector::class)]
#[CoversClass(ClassLikeAnalysis::class)]
final class ClassCollectorTest extends TestCase
{
    private const BASE_PATH = '/structarmed-test-project';

    private function collect(string $code): ClassNode
    {
        $nodes = $this->collectNodes($code);
        $this->assertNotEmpty($nodes, 'No class nodes collected');

        return $nodes[0];
    }

    /** @return ClassNode[] */
    private function collectNodes(string $code): array
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'src/Domain/'], self::BASE_PATH);
        $classCollector         = new ClassCollector($namespaceLayerResolver);
        $parser                 = (new ParserFactory())->createForNewestSupportedVersion();
        $ast                    = $parser->parse($code);

        $classCollector->setCurrentFile('/fake/path/Foo.php');

        $nodeTraverser = new NodeTraverser(new NameResolver(), $classCollector);
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

    public function testCollectsInterfaceExtends(): void
    {
        $classNode = $this->collect('<?php interface FooInterface extends FirstInterface, SecondInterface {}');

        $this->assertTrue($classNode->isInterface);
        $this->assertSame(['FirstInterface', 'SecondInterface'], $classNode->interfaceExtends);
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
        $classNode = $this->collect('<?php namespace App\Domain; trait FooTrait {}');

        $this->assertSame('App\Domain\FooTrait', $classNode->className);
        $this->assertTrue($classNode->isTrait);
        $this->assertFalse($classNode->isInterface);
    }

    public function testCollectsEnum(): void
    {
        $classNode = $this->collect('<?php enum Status: string implements Stringable { case Draft = "draft"; }');

        $this->assertSame('Status', $classNode->className);
        $this->assertSame(['Stringable'], $classNode->implements);
        $this->assertTrue($classNode->isEnum);
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

    public function testCollectsUsedTraitsInEnum(): void
    {
        $nodes    = $this->collectNodes('
        <?php
        trait HasLabel {
            public function label(): string { return $this->name; }
        }
        enum Status {
            use HasLabel;
            case Draft; case Published;
        }
        ');
        $enumNode = $nodes[1];

        $this->assertTrue($enumNode->isEnum);
        $this->assertSame(['HasLabel'], $enumNode->traits);
    }

    public function testCollectsUsedTraitsInTrait(): void
    {
        $nodes       = $this->collectNodes('
        <?php
        trait HasSlug {
            public function slug(): string { return strtolower($this->name()); }
        }
        trait HasName {
            use HasSlug;
            public function name(): string { return "Hello World"; }
        }
        ');
        $hasNameNode = $nodes[1];

        $this->assertTrue($hasNameNode->isTrait);
        $this->assertSame(['HasSlug'], $hasNameNode->traits);
    }

    public function testCollectsMethodReturnType(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): string { return "x"; } }');

        $this->assertCount(1, $classNode->methods);
        $this->assertTrue($classNode->methods[0]->hasReturnType);
        $this->assertSame('bar', $classNode->methods[0]->name);
    }

    public function testFiltersClassMethodsOncePerClassLike(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'src/Domain/'], self::BASE_PATH);
        $classCollector         = new ClassCollector($namespaceLayerResolver);
        $classLike              = new class ('Foo', [
            'stmts' => [new ClassMethod('__construct'), new ClassMethod('bar')],
        ]) extends Class_ {
            public int $getMethodsCallCount = 0;

            public function getMethods(): array
            {
                ++$this->getMethodsCallCount;

                return parent::getMethods();
            }
        };

        $classCollector->setCurrentFile('/fake/path/Foo.php');

        (new NodeTraverser(new NameResolver(), $classCollector))->traverse([$classLike]);

        $this->assertSame(1, $classLike->getMethodsCallCount);
        $this->assertSame(
            ['__construct', 'bar'],
            array_column($classCollector->getNodes()[0]->methods, 'name'),
        );
    }

    public function testMemoizesMethodsIndependentlyForSiblingClassLikesIncludingEmpty(): void
    {
        $nodes = $this->collectNodes(<<<'PHP'
            <?php

            class First
            {
                public function one(): void {}
                public function two(): void {}
            }

            class Second {}
            PHP);

        $this->assertCount(2, $nodes);
        $this->assertSame(['one', 'two'], array_column($nodes[0]->methods, 'name'));
        $this->assertSame([], $nodes[1]->methods);
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
        $classNode = $this->collect($code);

        $this->assertContains('Vendor\debug', $classNode->functionCalls);
    }

    public function testResolvesAliasedImportedFunctionCallToOriginalName(): void
    {
        $code      = <<<'PHP'
<?php
namespace App;

use function Other\debug as log;

class Foo {
    public function run(): void {
        log("x");
    }
}
PHP;
        $classNode = $this->collect($code);

        $this->assertContains('Other\debug', $classNode->functionCalls);
        $this->assertNotContains('log', $classNode->functionCalls);
        $this->assertNotContains('App\log', $classNode->functionCalls);
    }

    public function testResolvesQualifiedCallViaNamespaceAlias(): void
    {
        $code      = <<<'PHP'
<?php
namespace App;

use Foo;

class Bar
{
    public function run()
    {
        return Foo\strlen('test');
    }
}
PHP;
        $classNode = $this->collect($code);

        $this->assertContains('Foo\strlen', $classNode->functionCalls);
        $this->assertNotContains('App\Foo\strlen', $classNode->functionCalls);
    }

    public function testKeepsNativeFunctionCallsUnqualifiedInsideNamespace(): void
    {
        $classNode = $this->collect(
            '<?php namespace App\Support; class Foo { public function bar(): int { return strlen("x"); } }'
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
        $classNode = $this->collect($code);

        $this->assertContains('App\Support\debug', $classNode->functionCalls);
    }

    public function testKeepsUnresolvedFunctionCallsAsWrittenInsideNamespace(): void
    {
        $classNode = $this->collect(
            '<?php namespace App\Support; class Foo { public function bar(): void { missing_function("x"); } }'
        );

        $this->assertContains('missing_function', $classNode->functionCalls);
        $this->assertNotContains('App\Support\missing_function', $classNode->functionCalls);
    }

    public function testResolvesNamespacedFunctionCallWhenNameShadowsInternalFunction(): void
    {
        $code      = <<<'PHP'
<?php
namespace App;

function strlen(string $s)
{
    return 100;
}

class Bar
{
    public function run()
    {
        return strlen('test');
    }
}
PHP;
        $classNode = $this->collect($code);

        $this->assertContains('App\strlen', $classNode->functionCalls);
        $this->assertNotContains('strlen', $classNode->functionCalls);
    }

    public function testExplicitUseFunctionOverridesLocalNamespacedDefinition(): void
    {
        $code      = <<<'PHP'
<?php
namespace App;

use function strlen;

function strlen(string $s)
{
    return 100;
}

class Bar
{
    public function run()
    {
        return strlen('test');
    }
}
PHP;
        $classNode = $this->collect($code);

        $this->assertContains('strlen', $classNode->functionCalls);
        $this->assertNotContains('App\strlen', $classNode->functionCalls);
    }

    public function testCollectsSuperglobals(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { $x = $_GET["id"]; } }');

        $this->assertContains('$_GET', $classNode->superglobals);
    }

    public function testCollectsExitAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { exit(1); } }');

        $this->assertContains('exit', $classNode->languageConstructs);
        $this->assertNotContains('exit', $classNode->functionCalls);
    }

    public function testCollectsDieAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { die("error"); } }');

        $this->assertContains('die', $classNode->languageConstructs);
        $this->assertNotContains('die', $classNode->functionCalls);
    }

    public function testDeduplicatesLanguageConstructs(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public function bar(): void { exit(0); exit(1); } }'
        );

        $this->assertSame(['exit'], $classNode->languageConstructs);
    }

    public function testKeepsIncludeFamilyConstructsDistinct(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public function bar(): void {'
            . ' include "a.php"; include_once "b.php";'
            . ' require "c.php"; require_once "d.php"; } }'
        );

        $this->assertSame(
            ['include', 'include_once', 'require', 'require_once'],
            $classNode->languageConstructs
        );
    }

    public function testCollectsEchoAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { echo "hello"; } }');

        $this->assertContains('echo', $classNode->languageConstructs);
        $this->assertNotContains('echo', $classNode->functionCalls);
    }

    public function testCollectsPrintAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { print "hello"; } }');

        $this->assertContains('print', $classNode->languageConstructs);
        $this->assertNotContains('print', $classNode->functionCalls);
    }

    public function testCollectsIncludeAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { include "file.php"; } }');

        $this->assertContains('include', $classNode->languageConstructs);
    }

    public function testCollectsIncludeOnceAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { include_once "file.php"; } }');

        $this->assertContains('include_once', $classNode->languageConstructs);
    }

    public function testCollectsRequireAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { require "file.php"; } }');

        $this->assertContains('require', $classNode->languageConstructs);
    }

    public function testCollectsRequireOnceAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { require_once "file.php"; } }');

        $this->assertContains('require_once', $classNode->languageConstructs);
    }

    public function testCollectsIssetAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { $x = isset($y); } }');

        $this->assertContains('isset', $classNode->languageConstructs);
        $this->assertNotContains('isset', $classNode->functionCalls);
    }

    public function testCollectsEmptyAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { $x = empty($y); } }');

        $this->assertContains('empty', $classNode->languageConstructs);
        $this->assertNotContains('empty', $classNode->functionCalls);
    }

    public function testCollectsUnsetAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { unset($x); } }');

        $this->assertContains('unset', $classNode->languageConstructs);
        $this->assertNotContains('unset', $classNode->functionCalls);
    }

    public function testCollectsEvalAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { eval("echo 1;"); } }');

        $this->assertContains('eval', $classNode->languageConstructs);
        $this->assertNotContains('eval', $classNode->functionCalls);
    }

    public function testCollectsListAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { list($a, $b) = [1, 2]; } }');

        $this->assertContains('list', $classNode->languageConstructs);
    }

    public function testCollectsShortListDestructuringAsLanguageConstruct(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { [$a, $b] = [1, 2]; } }');

        $this->assertContains('list', $classNode->languageConstructs);
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
        $this->assertSame(3, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testAggregatesNestedClosureBranchesIntoEnclosingMethodComplexity(): void
    {
        $code      = <<<'PHP'
<?php
class Foo {
    public function simple(array $list): array {
        return array_filter($list, function ($x) {
            return $x > 0 && $x < 10;
        });
    }
}
PHP;
        $classNode = $this->collect($code);

        // Base 1 + the closure's && = 2.
        $this->assertSame(2, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testAggregatesArrowFunctionBranchesIntoEnclosingMethodComplexity(): void
    {
        $code      = <<<'PHP'
<?php
class Foo {
    public function simple(array $list): array {
        return array_map(fn ($x) => $x > 0 ? 1 : 0, $list);
    }
}
PHP;
        $classNode = $this->collect($code);

        // Base 1 + the arrow function's ternary = 2.
        $this->assertSame(2, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testAggregatesMethodBranchesAndNestedClosureBranches(): void
    {
        $code      = <<<'PHP'
<?php
class Foo {
    public function m($a, array $list): array {
        if ($a) {
            return array_filter($list, fn ($x) => $x > 0 && $x < 5);
        }

        return [];
    }
}
PHP;
        $classNode = $this->collect($code);

        // Base 1 + own if + the closure's && = 3.
        $this->assertSame(3, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testAggregatesAssignedClosureBranchesWithSubsequentMethodBranch(): void
    {
        $code      = <<<'PHP'
<?php
class Foo {
    public function simple(array $list): array {
        $data = array_filter($list, function ($x) {
            return $x > 0 && $x < 10;
        });

        if ($data === []) {
            return [1];
        }

        return $data;
    }
}
PHP;
        $classNode = $this->collect($code);

        // Base 1 + the closure's && + own if = 3.
        $this->assertSame(3, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testAggregatesMultipleIfsInsideClosureIntoEnclosingMethodComplexity(): void
    {
        $code      = <<<'PHP'
<?php
class Foo {
    public function simple(array $list): array {
        return array_filter($list, function ($x) {
            if ($x < 0) {
                return false;
            }

            if ($x > 100) {
                return false;
            }

            if ($x === 42) {
                return false;
            }

            return true;
        });
    }
}
PHP;
        $classNode = $this->collect($code);

        // Base 1 + the closure's three ifs = 4.
        $this->assertSame(4, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testAggregatesAnonymousClassMethodBranchesIntoEnclosingMethod(): void
    {
        $code      = <<<'PHP'
<?php
class Foo {
    public function outer(): object {
        return new class {
            public function inner($x): int {
                if ($x) {
                    return 1;
                }

                return 0;
            }
        };
    }
}
PHP;
        $classNode = $this->collect($code);

        // Base 1 + the anonymous class method's if = 2.
        $this->assertSame(2, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testAggregatesOwnAndAnonymousClassMethodBranchesWithoutDoubleCounting(): void
    {
        $code      = <<<'PHP'
<?php
class Foo {
    public function outer($a): ?object {
        if ($a) {
            return new class {
                public function first($x): int {
                    if ($x) {
                        return 1;
                    }

                    return 0;
                }

                public function second($y): int {
                    if ($y) {
                        return 1;
                    }

                    return 0;
                }
            };
        }

        return null;
    }
}
PHP;
        $classNode = $this->collect($code);

        // Base 1 + own if + first()'s if + second()'s if = 4, each counted once.
        $this->assertSame(4, $classNode->methods[0]->cyclomaticComplexity);
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

    public function testCollectsImportUsedOnlyInDocblockAsDependency(): void
    {
        // StructArmed does not read docblocks; it treats the `use` import itself as the
        // dependency, so a class imported solely for a `@param`/`@return`/`@var` annotation
        // is still collected — even though the symbol is never referenced in real code.
        $code      = <<<'PHP'
<?php
namespace App\Domain;

use App\Infrastructure\Persistence\OrderRepository;

class Foo
{
    /**
     * @param OrderRepository $repository
     */
    public function handle($repository): void
    {
    }
}
PHP;
        $classNode = $this->collect($code);

        $this->assertContains('App\Infrastructure\Persistence\OrderRepository', $classNode->dependencies);
    }

    public function testDoesNotShareImportsAcrossNamespaceBlocks(): void
    {
        $nodes = $this->collectNodes(<<<'PHP'
<?php

namespace App\First {
    use App\Infrastructure\Service;

    final class First {}
}

namespace App\Second {
    final class Second {}
}
PHP);

        $this->assertCount(2, $nodes);
        $this->assertContains('App\Infrastructure\Service', $nodes[0]->dependencies);
        $this->assertNotContains('App\Infrastructure\Service', $nodes[1]->dependencies);
    }

    public function testDoesNotShareNamespaceImportsOrFullyQualifiedDependenciesAcrossNamespaceBlocks(): void
    {
        $nodes = $this->collectNodes(<<<'PHP'
<?php

namespace App\First {
    use App\Infrastructure;

    final class First
    {
        public function __construct(
            private Infrastructure\Service $service,
            private \App\Infrastructure\Repository $repository,
        ) {
        }
    }
}

namespace App\Second {
    final class Second {}
}
PHP);

        $this->assertCount(2, $nodes);
        $this->assertContains('App\Infrastructure', $nodes[0]->dependencies);
        $this->assertContains('App\Infrastructure\Service', $nodes[0]->dependencies);
        $this->assertContains('App\Infrastructure\Repository', $nodes[0]->dependencies);
        $this->assertNotContains('App\Infrastructure', $nodes[1]->dependencies);
        $this->assertNotContains('App\Infrastructure\Service', $nodes[1]->dependencies);
        $this->assertNotContains('App\Infrastructure\Repository', $nodes[1]->dependencies);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function groupedImportDependencyProvider(): iterable
    {
        yield 'class imports' => [
            <<<'PHP'
<?php
namespace App\Domain;

use App\Infrastructure\{Bar, Baz};

class Foo
{
    public function __construct(private Bar $bar, private Baz $baz)
    {
    }
}
PHP,
            [
                'App\Infrastructure\Bar',
                'App\Infrastructure\Baz',
            ],
        ];

        yield 'constant imports' => [
            <<<'PHP'
<?php
namespace App\Domain;

use const App\Infrastructure\Config\{FEATURE_ENABLED, OTHER_FLAG};

class Foo
{
    public function isEnabled(): bool
    {
        return FEATURE_ENABLED && OTHER_FLAG;
    }
}
PHP,
            [
                'App\Infrastructure\Config\FEATURE_ENABLED',
                'App\Infrastructure\Config\OTHER_FLAG',
            ],
        ];

        yield 'function imports' => [
            <<<'PHP'
<?php
namespace App\Domain;

use function App\Infrastructure\Support\{debug, trace};

class Foo
{
    public function run(): void
    {
        debug();
        trace();
    }
}
PHP,
            [
                'App\Infrastructure\Support\debug',
                'App\Infrastructure\Support\trace',
            ],
        ];
    }

    /**
     * @param list<string> $expectedDependencies
     */
    #[DataProvider('groupedImportDependencyProvider')]
    public function testCollectsGroupedImportedUsageAsDependenciesWithoutShortNames(
        string $code,
        array $expectedDependencies
    ): void {
        $classNode = $this->collect($code);

        $this->assertSame($expectedDependencies, $classNode->dependencies);
    }

    public function testCollectsImportedConstantUsageAsDependency(): void
    {
        $code      = <<<'PHP'
<?php
namespace App\Domain;

use const App\Infrastructure\Config\FEATURE_ENABLED;

class Foo
{
    public function isEnabled(): bool
    {
        return FEATURE_ENABLED;
    }
}
PHP;
        $classNode = $this->collect($code);

        $this->assertContains('App\Infrastructure\Config\FEATURE_ENABLED', $classNode->dependencies);
    }

    public function testCollectsFullyQualifiedDependencies(): void
    {
        $classNode = $this->collect('<?php class Foo { public function bar(): void { new \DateTimeImmutable(); } }');

        $this->assertContains('DateTimeImmutable', $classNode->dependencies);
    }

    public function testShortNameExtraction(): void
    {
        $classNode = $this->collect('<?php namespace App\Domain; final class OrderEntity {}');

        $this->assertSame('App\Domain\OrderEntity', $classNode->className);
        $this->assertSame('OrderEntity', $classNode->shortName());
    }

    public function testCollectsDependencyForCurrentNamespace(): void
    {
        $code      = <<<'PHP'
<?php

namespace App\SomeSub;

class Foo
{
    public function bar(): void
    {
        echo Bar::class;
    }
}
PHP;
        $classNode = $this->collect($code);

        $this->assertContains('App\SomeSub\Bar', $classNode->dependencies);
    }

    public function testDoesNotCollectFullyQualifiedTrueFalseNullAsDependencies(): void
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

    public function testIgnoresClassMethodNodesOutsideTrackedClassLike(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'src/Domain/'], self::BASE_PATH);
        $classCollector         = new ClassCollector($namespaceLayerResolver);
        $classMethod            = new ClassMethod('orphan');

        $classCollector->setCurrentFile('/fake/path/Foo.php');

        $classCollector->enterNode($classMethod);
        $classCollector->leaveNode($classMethod);

        $this->assertSame([], $classCollector->getNodes());
    }
}
