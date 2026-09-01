<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Analyser;

use Boundwize\StructArmed\Analyser\AnalysisNodeCollector;
use Boundwize\StructArmed\Analyser\AnonymousClassNode;
use Boundwize\StructArmed\Analyser\ClassLikeAnalysis;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\EnumCaseNode;
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

#[CoversClass(AnonymousClassNode::class)]
#[CoversClass(AnalysisNodeCollector::class)]
#[CoversClass(ClassLikeAnalysis::class)]
#[CoversClass(EnumCaseNode::class)]
final class AnalysisNodeCollectorTest extends TestCase
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
        return $this->makeCollector($code)->getClassNodes();
    }

    /** @return list<AnonymousClassNode> */
    private function collectAnonymousClassNodes(string $code): array
    {
        return $this->makeCollector($code)->getAnonymousClassNodes();
    }

    private function makeCollector(string $code): AnalysisNodeCollector
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'src/Domain/'], self::BASE_PATH);
        $analysisNodeCollector  = new AnalysisNodeCollector($namespaceLayerResolver);
        $parser                 = (new ParserFactory())->createForNewestSupportedVersion();
        $ast                    = $parser->parse($code);

        $analysisNodeCollector->setCurrentFile('/fake/path/Foo.php');

        $nodeTraverser = new NodeTraverser(new NameResolver(), $analysisNodeCollector);
        $nodeTraverser->traverse($ast ?? []);

        return $analysisNodeCollector;
    }

    public function testCollectsFileReferencesFromProceduralCode(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'function handle(Contract $contract): void {}' . "\n"
            . 'function check(object $value): bool { return $value instanceof Contract; }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame(
            ['/fake/path/Foo.php' => ['App\Contract']],
            $analysisNodeCollector->getFileReferences()
        );
    }

    public function testDoesNotCollectFileReferencesFromClassBodies(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Checker { public function check(object $value): bool'
            . ' { return $value instanceof Contract; } }';

        $analysisNodeCollector = $this->makeCollector($code);

        // References inside a named class-like land on its ClassNode
        // dependencies, not in the file-level references.
        $this->assertSame([], $analysisNodeCollector->getFileReferences());
    }

    public function testCollectsClassNameShapedStringValuesAsFileReferences(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Checker { public function check(object $obj): bool {'
            . ' $contract = \'App\\Contract\'; return $obj instanceof $contract; } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame(
            ['/fake/path/Foo.php' => ['App\Contract']],
            $analysisNodeCollector->getFileReferences()
        );
    }

    public function testCollectsLeadingBackslashStringValuesAsNormalizedFileReferences(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'interface_exists(\'\\App\\Contract\');';

        $analysisNodeCollector = $this->makeCollector($code);

        // '\App\Contract' is a valid fully-qualified spelling; the stored
        // name drops the leading separator so it matches ClassNode::$className.
        $this->assertSame(
            ['/fake/path/Foo.php' => ['App\Contract']],
            $analysisNodeCollector->getFileReferences()
        );
    }

    public function testCollectsLeadingBackslashStringInstantiationsNormalized(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Factory { public function make(): object { return new (\'\\App\\Service\')(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame(
            ['/fake/path/Foo.php' => ['App\Service']],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testResolvesConcatenatedConstantClassExpressionAsInstantiation(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Factory { public function make(): object { return new (\'App\\Service\' . 1)(); } }' . "\n"
            . 'final class Other { public function make(): object { return new (1 + 1)(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        // 'App\Service1' is a class-shaped string; `1 + 1` is not a constant
        // class expression the collector evaluates at all.
        $this->assertSame(
            ['/fake/path/Foo.php' => ['App\Service1']],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testDoesNotCollectNonClassNameShapedStringValues(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Greeter { public function greet(): string {'
            . ' $mode = true ? \'foo-bar\' : \'hello world\'; return $mode . \'123abc\' . \'\'; } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame([], $analysisNodeCollector->getFileReferences());
    }

    public function testCollectsInstantiations(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Factory { public function make(): object { return new Service(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame(
            ['/fake/path/Foo.php' => ['App\Service']],
            $analysisNodeCollector->getFileInstantiations()
        );
        $this->assertSame([], $analysisNodeCollector->getFileReferences());
    }

    public function testResolvesSelfStaticAndParentInstantiations(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'class Repository extends BaseRepository {' . "\n"
            . '    public function one(): self { return new self(); }' . "\n"
            . '    public function two(): static { return new static(); }' . "\n"
            . '    public function three(): BaseRepository { return new parent(); }' . "\n"
            . '}';

        $analysisNodeCollector = $this->makeCollector($code);

        // `static` is late-bound, so it is recorded as a marker the analyser
        // resolves to Repository and its descendants.
        $this->assertSame(
            [
                '/fake/path/Foo.php' => [
                    'App\Repository',
                    AnalysisNodeCollector::deferredInstantiationMarker('static', 'App\Repository'),
                    'App\BaseRepository',
                ],
            ],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testDeferredInstantiationMarkerRoundTrips(): void
    {
        foreach (['self', 'static', 'parent'] as $keyword) {
            $marker = AnalysisNodeCollector::deferredInstantiationMarker($keyword, 'App\\Factory');

            $this->assertSame(
                [$keyword, 'App\\Factory'],
                AnalysisNodeCollector::parseDeferredInstantiationMarker($marker)
            );
        }

        $this->assertNull(AnalysisNodeCollector::parseDeferredInstantiationMarker('App\\Factory'));
        $this->assertNull(AnalysisNodeCollector::parseDeferredInstantiationMarker('other@App\\Factory'));
    }

    public function testDoesNotRecordStringWithMarkerSeparatorAsInstantiation(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'class Host { public function make(): object { return new (\'other@App\\Host\')(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        // `@` never occurs in a class name, and only self/static/parent form
        // a marker: anything else records nothing.
        $this->assertSame([], $analysisNodeCollector->getFileInstantiations());
    }

    public function testRecordsTraitSelfStaticAndParentInstantiationsAsMarkers(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'trait Factory {' . "\n"
            . '    public static function createParent(): object { return new parent(); }' . "\n"
            . '    public static function viaClassConstant(): object { return new (parent::class)(); }' . "\n"
            . '    public static function createSelf(): object { return new self(); }' . "\n"
            . '    public static function createStatic(): object { return new static(); }' . "\n"
            . '    public static function selfViaClassConstant(): object { return new (self::class)(); }' . "\n"
            . '    public static function staticViaClassConstant(): object { return new (static::class)(); }' . "\n"
            . '}';

        $analysisNodeCollector = $this->makeCollector($code);

        // A trait is never instantiated itself: each marker is resolved by the
        // analyser against every class using the trait. `new (X::class)()`
        // yields the same marker as `new X()`, deduplicated per file.
        $this->assertSame(
            [
                '/fake/path/Foo.php' => [
                    AnalysisNodeCollector::deferredInstantiationMarker('parent', 'App\Factory'),
                    AnalysisNodeCollector::deferredInstantiationMarker('self', 'App\Factory'),
                    AnalysisNodeCollector::deferredInstantiationMarker('static', 'App\Factory'),
                ],
            ],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testRecordsClassSelfAsNameAndStaticAsMarker(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'class Model extends Base {' . "\n"
            . '    public static function createSelf(): object { return new self(); }' . "\n"
            . '    public static function staticViaClassConstant(): object { return new (static::class)(); }' . "\n"
            . '    public static function createParent(): object { return new parent(); }' . "\n"
            . '}';

        $analysisNodeCollector = $this->makeCollector($code);

        // `self` and `parent` are lexically bound; `static` is late-bound, so
        // its marker lets the analyser include the descendants of Model.
        $this->assertSame(
            [
                '/fake/path/Foo.php' => [
                    'App\Model',
                    AnalysisNodeCollector::deferredInstantiationMarker('static', 'App\Model'),
                    'App\Base',
                ],
            ],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testResolvesParentInsideAnonymousClassAgainstTheAnonymousClass(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'trait Factory {' . "\n"
            . '    public static function create(): object {' . "\n"
            . '        return new class extends Base {' . "\n"
            . '            public static function createParent(): object { return new parent(); }' . "\n"
            . '            public static function createSelf(): object { return new self(); }' . "\n"
            . '            public static function createStatic(): object { return new static(); }' . "\n"
            . '        };' . "\n"
            . '    }' . "\n"
            . '    public static function createOwnParent(): object { return new parent(); }' . "\n"
            . '}' . "\n"
            . 'class Host extends Other {' . "\n"
            . '    public function make(): object {' . "\n"
            . '        return new class { public function p(): object { return new parent(); } };' . "\n"
            . '    }' . "\n"
            . '    public function own(): object { return new parent(); }' . "\n"
            . '}';

        $analysisNodeCollector = $this->makeCollector($code);

        // `parent` belongs to the innermost class-like: the anonymous class,
        // not the enclosing trait or class. An anonymous class without a
        // parent (and `self`/`static` inside one) resolves to nothing.
        $this->assertSame(
            [
                '/fake/path/Foo.php' => [
                    'App\Base',
                    AnalysisNodeCollector::deferredInstantiationMarker('parent', 'App\Factory'),
                    'App\Other',
                ],
            ],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testDoesNotRecordParentAccessWithoutNewAsInstantiation(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'trait Factory {' . "\n"
            . '    public function call(): mixed { return parent::build(); }' . "\n"
            . '    public function constant(): mixed { return parent::NAME; }' . "\n"
            . '    public function name(): string { return parent::class; }' . "\n"
            . '    public function property(): mixed { return parent::$shared; }' . "\n"
            . '}' . "\n"
            . 'class Host extends Base { use Factory; public function __construct() { parent::__construct(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        // Only `new` instantiates: static calls, constants, ::class, and
        // static properties on parent never mark it (or a trait marker).
        $this->assertSame([], $analysisNodeCollector->getFileInstantiations());
    }

    public function testResolvesConstantClassExpressionInstantiations(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Maker {' . "\n"
            . '    public function fromClassConstant(): object { return new (Base::class)(); }' . "\n"
            . '    public function fromString(): object { return new (\'App\\StringBase\')(); }' . "\n"
            . '    public function fromConcat(): object { return new (\'App\\\\\' . \'Joined\')(); }' . "\n"
            . '}';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame(
            ['/fake/path/Foo.php' => ['App\Base', 'App\StringBase', 'App\Joined']],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testResolvesSelfClassConstantInstantiation(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'class Registry { public function fresh(): object { return new (self::class)(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame(
            ['/fake/path/Foo.php' => ['App\Registry']],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testIgnoresRuntimeFedDynamicInstantiations(): void
    {
        // The class name flows in from a call site or property the collector
        // cannot see — part of the documented scanned-code boundary.
        $code = '<?php namespace App;' . "\n"
            . 'function make(string $class): object { return new $class(); }' . "\n"
            . 'final class Holder { public function __construct(private string $class) {}'
            . ' public function make(): object { return new ($this->class)(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame([], $analysisNodeCollector->getFileInstantiations());
    }

    public function testResolvesChainedReflectionConstruction(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Booter { public function boot(): object {'
            . ' return (new \ReflectionClass(Base::class))->newInstance(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        // ReflectionClass itself is instantiated, and so is the class it
        // reflects.
        $this->assertSame(
            ['/fake/path/Foo.php' => ['ReflectionClass', 'App\Base']],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testResolvesNullsafeChainedReflectionConstruction(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Booter { public function boot(): ?object {'
            . ' return (new \\ReflectionClass(\'App\\Child\'))?->newInstanceWithoutConstructor(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame(
            ['/fake/path/Foo.php' => ['ReflectionClass', 'App\Child']],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testIgnoresReflectionConstructionWithUnresolvableTarget(): void
    {
        // Runtime-named targets and variable-held reflections stay within the
        // documented scanned-code boundary — nothing is recorded for them.
        $code = '<?php namespace App;' . "\n"
            . 'final class Booter { public function boot(\\ReflectionClass $r, string $name): object {'
            . ' $other = new \\ReflectionClass($name); return $r->newInstance() ?? $other->newInstance(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame(
            ['/fake/path/Foo.php' => ['ReflectionClass']],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testIgnoresNonReflectionChainedConstructionCalls(): void
    {
        // A newInstance() chained on something that is not a resolvable
        // ReflectionClass records nothing for the chain — only the receiver's
        // own `new` counts where resolvable.
        $code = '<?php namespace App;' . "\n"
            . 'final class Booter { public function boot(object $x): mixed {'
            . ' $a = (new Container())->newInstance(); $b = (new ($x::class))->newInstance();'
            . ' $c = (new \\ReflectionClass())->newInstance(); return $a ?? $b ?? $c; } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame(
            ['/fake/path/Foo.php' => ['App\Container', 'ReflectionClass']],
            $analysisNodeCollector->getFileInstantiations()
        );
    }

    public function testIgnoresOrdinaryMethodCalls(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Caller { public function run(object $service): mixed {'
            . ' return $service->handle(); } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame([], $analysisNodeCollector->getFileInstantiations());
    }

    public function testIgnoresUnresolvableClassNameExpressions(): void
    {
        // Runtime-dependent class expressions and non-class-shaped strings
        // resolve to nothing — no instantiation is recorded.
        $code = '<?php namespace App;' . "\n"
            . 'final class Maker { public function make(string $suffix, object $obj): object {'
            . ' $a = new (\'App\\\\\' . $suffix)(); $b = new ($obj::class)();'
            . ' $c = new (\'not a class name!\')(); return $a ?? $b ?? $c; } }';

        $analysisNodeCollector = $this->makeCollector($code);

        $this->assertSame([], $analysisNodeCollector->getFileInstantiations());
    }

    public function testDoesNotCollectAnonymousInstantiations(): void
    {
        $code = '<?php namespace App;' . "\n"
            . 'final class Maker { public function make(): object { return new class {}; } }';

        $analysisNodeCollector = $this->makeCollector($code);

        // Anonymous classes are tracked as AnonymousClassNodes, and their
        // known declaration does not make any named class instantiable.
        $this->assertSame([], $analysisNodeCollector->getFileInstantiations());
    }

    public function testIgnoresRelativeInstantiationOutsideClassScope(): void
    {
        // `new self` outside a class parses but cannot be resolved to a name;
        // PHP itself rejects it at runtime.
        $analysisNodeCollector = $this->makeCollector('<?php namespace App; new self();');

        $this->assertSame([], $analysisNodeCollector->getFileInstantiations());
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

    public function testCollectsAnonymousClassNodeDeclaredInsideMethod(): void
    {
        $anonymousClassNodes = $this->collectAnonymousClassNodes('<?php
        namespace App;
        class HandlerFactory {
            public function make(): BaseHandler { return new class extends BaseHandler {}; }
        }');

        $this->assertCount(1, $anonymousClassNodes);
        $this->assertSame('App\BaseHandler', $anonymousClassNodes[0]->extends);
        $this->assertSame('/fake/path/Foo.php', $anonymousClassNodes[0]->file);
    }

    public function testCollectsTopLevelAnonymousClassNodeInFileWithoutNamedClasses(): void
    {
        $anonymousClassNodes = $this->collectAnonymousClassNodes('<?php
        use App\BaseHandler;
        return new class extends BaseHandler {};');

        $this->assertCount(1, $anonymousClassNodes);
        $this->assertSame('App\BaseHandler', $anonymousClassNodes[0]->extends);
    }

    public function testCollectsAnonymousClassNodeWithoutExtends(): void
    {
        $anonymousClassNodes = $this->collectAnonymousClassNodes('<?php
        namespace App;
        class HandlerFactory {
            public function make(): object { return new class implements \Stringable {
                public function __toString(): string { return ""; }
            }; }
        }');

        $this->assertCount(1, $anonymousClassNodes);
        $this->assertNull($anonymousClassNodes[0]->extends);
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

    public function testCollectsNoTraitsFromInterfaceWithTraitUse(): void
    {
        // PHP rejects trait use inside interfaces at compile time, but the
        // parser accepts it, so the collector must skip such statements.
        $nodes = $this->collectNodes('<?php interface Foo { use FirstTrait; }');

        $this->assertCount(1, $nodes);
        $this->assertTrue($nodes[0]->isInterface);
        $this->assertSame([], $nodes[0]->traits);
    }

    public function testCollectsUsedTraitsInAnonymousClass(): void
    {
        $anonymousClassNodes = $this->collectAnonymousClassNodes('<?php
        namespace App;
        return new class { use FirstTrait, SecondTrait; };');

        $this->assertCount(1, $anonymousClassNodes);
        $this->assertSame(['App\FirstTrait', 'App\SecondTrait'], $anonymousClassNodes[0]->traits);
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

    public function testCollectsMagicMethodFlag(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public function __construct() {} public function __DoThing() {} }'
        );

        $this->assertTrue($classNode->methods[0]->isMagic);
        $this->assertFalse($classNode->methods[1]->isMagic);
    }

    /**
     * Node dispatch is keyed by exact node class, as the parser never
     * subclasses its nodes, so a hand-built Class_ (not a subclass of it) is
     * traversed like a parsed one.
     */
    public function testCollectsEachClassMethodOnce(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(['Domain' => 'src/Domain/'], self::BASE_PATH);
        $analysisNodeCollector  = new AnalysisNodeCollector($namespaceLayerResolver);
        $class                  = new Class_('Foo', [
            'stmts' => [new ClassMethod('__construct'), new ClassMethod('bar')],
        ]);

        $analysisNodeCollector->setCurrentFile('/fake/path/Foo.php');

        (new NodeTraverser(new NameResolver(), $analysisNodeCollector))->traverse([$class]);

        $this->assertSame(
            ['__construct', 'bar'],
            array_column($analysisNodeCollector->getClassNodes()[0]->methods, 'name'),
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

    public function testCollectsEnumCases(): void
    {
        $classNode = $this->collect(<<<'PHP'
            <?php
            enum Suit: string
            {
                case Hearts = 'H';
                case Spades = 'S';

                public const Wild = self::Spades;

                public function label(): string
                {
                    return $this->name;
                }
            }
            PHP);

        $this->assertTrue($classNode->isEnum);
        $this->assertTrue($classNode->isBackedEnum());
        $this->assertSame('string', $classNode->enumBackingType);
        $this->assertSame(['Hearts', 'Spades'], array_column($classNode->enumCases, 'name'));
        $this->assertSame([4, 5], array_column($classNode->enumCases, 'line'));
        $this->assertSame(['H', 'S'], array_column($classNode->enumCases, 'value'));
        $this->assertTrue($classNode->enumCases[0]->hasResolvedValue());
        $this->assertSame(['Wild'], array_column($classNode->constants, 'name'));
        $this->assertSame(['label'], array_column($classNode->methods, 'name'));
    }

    public function testResolvesImportedClassNameInEnumCaseValue(): void
    {
        $classNode = $this->collect(<<<'PHP'
            <?php
            namespace App;

            use Vendor\Foo as AliasedFoo;

            enum Type: string
            {
                case Foo = AliasedFoo::class;
                case Own = self::class;
            }
            PHP);

        $this->assertSame(['Vendor\Foo', 'App\Type'], array_column($classNode->enumCases, 'value'));
    }

    public function testIgnoresEnumCaseDeclaredInAnonymousClass(): void
    {
        // php-parser accepts a case in a class body; only PHP's compiler
        // rejects it, so the collector must not attribute it to any class.
        $classNode = $this->collect(<<<'PHP'
            <?php
            enum Outer: int
            {
                case One = 1;

                public function make(): object
                {
                    return new class {
                        case Stray = 2;
                    };
                }
            }
            PHP);

        $this->assertSame(['One'], array_column($classNode->enumCases, 'name'));
    }

    public function testCollectsIntBackedEnumCaseValues(): void
    {
        $classNode = $this->collect(
            '<?php enum Status: int { case Active = 1; case Shifted = 1 << 3; case Unresolvable = PHP_INT_MAX; }'
        );

        $this->assertSame('int', $classNode->enumBackingType);
        $this->assertSame([1, 8, null], array_column($classNode->enumCases, 'value'));
        // Still a backed case: the analyser just cannot evaluate PHP_INT_MAX.
        $this->assertTrue($classNode->isBackedEnum());
        $this->assertFalse($classNode->enumCases[2]->hasResolvedValue());
    }

    public function testPureEnumHasNoBackingTypeOrCaseValues(): void
    {
        $classNode = $this->collect('<?php enum Suit { case Hearts; case Spades; }');

        $this->assertTrue($classNode->isEnum);
        $this->assertFalse($classNode->isBackedEnum());
        $this->assertNull($classNode->enumBackingType);
        $this->assertSame([null, null], array_column($classNode->enumCases, 'value'));
    }

    public function testNonEnumHasNoEnumCases(): void
    {
        $classNode = $this->collect('<?php class Foo { const A = 1; }');

        $this->assertSame([], $classNode->enumCases);
        $this->assertNull($classNode->enumBackingType);
        $this->assertFalse($classNode->isBackedEnum());
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

    public function testPropertiesFollowSourceOrderWhenConstructorPrecedesDeclaredProperties(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public function __construct(public int $id) {} public string $name; }'
        );

        $this->assertSame(['id', 'name'], array_column($classNode->properties, 'name'));
    }

    public function testPropertiesFollowSourceOrderWhenDeclaredPropertiesPrecedeConstructor(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public string $name; public function __construct(public int $id) {} }'
        );

        $this->assertSame(['name', 'id'], array_column($classNode->properties, 'name'));
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

    public function testCollectsNamedArgumentExitAsLanguageConstruct(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public function bar(): never { exit(status: 1); } }'
        );

        $this->assertContains('exit', $classNode->languageConstructs);
        $this->assertNotContains('exit', $classNode->functionCalls);
    }

    public function testCollectsNamedArgumentDieAsLanguageConstruct(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public function bar(): never { die(status: 1); } }'
        );

        $this->assertContains('die', $classNode->languageConstructs);
        $this->assertNotContains('die', $classNode->functionCalls);
    }

    public function testCollectsUppercaseNamedArgumentExitAsLanguageConstruct(): void
    {
        $classNode = $this->collect(
            '<?php class Foo { public function bar(): never { EXIT(status: 1); } }'
        );

        $this->assertContains('exit', $classNode->languageConstructs);
        $this->assertNotContains('EXIT', $classNode->functionCalls);
        $this->assertNotContains('exit', $classNode->functionCalls);
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

    public function testEarlyReturnsPreserveChildNodeAnalysis(): void
    {
        $classNode = $this->collect(<<<'PHP'
            <?php
            class Foo
            {
                public function run(): void
                {
                    if (isset($_GET['enabled'])) {
                        process(new \DateTimeImmutable());
                    }
                }
            }
            PHP);

        $this->assertSame(2, $classNode->methods[0]->cyclomaticComplexity);
        $this->assertContains('isset', $classNode->languageConstructs);
        $this->assertContains('$_GET', $classNode->superglobals);
        $this->assertContains('process', $classNode->functionCalls);
        $this->assertContains('DateTimeImmutable', $classNode->dependencies);
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

    public function testCountsNullCoalescingAsCyclomaticComplexity(): void
    {
        $classNode = $this->collect(<<<'PHP'
            <?php
            final class Foo
            {
                public function value(?string $a, ?string $b): string
                {
                    $a ??= 'a';

                    return $a ?? $b ?? 'b';
                }
            }
            PHP);

        // Base 1 + ??= + two ?? operators = 4.
        $this->assertSame(4, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testCountsNullsafeOperatorAsCyclomaticComplexity(): void
    {
        $classNode = $this->collect(<<<'PHP'
            <?php
            final class Foo
            {
                public function name(?User $user): ?string
                {
                    return $user?->profile()?->name;
                }
            }
            PHP);

        // Base 1 + two ?-> operators (method call + property fetch) = 3.
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

    public function testCountsEachMatchArmAsComplexityBranch(): void
    {
        $code      = <<<'PHP'
<?php
class Foo {
    public function bar(int $x): string {
        return match ($x) {
            1 => 'a',
            2 => 'b',
            3 => 'c',
            default => 'd',
        };
    }
}
PHP;
        $classNode = $this->collect($code);

        // Base 1 + 4 match arms = 5.
        $this->assertSame(5, $classNode->methods[0]->cyclomaticComplexity);
    }

    public function testMatchAndEquivalentSwitchHaveEqualComplexity(): void
    {
        $matchCode  = <<<'PHP'
<?php
class Foo {
    public function bar(int $x): string {
        return match ($x) {
            1 => 'a',
            2 => 'b',
            default => 'c',
        };
    }
}
PHP;
        $switchCode = <<<'PHP'
<?php
class Foo {
    public function bar(int $x): string {
        switch ($x) {
            case 1:
                return 'a';
            case 2:
                return 'b';
            default:
                return 'c';
        }
    }
}
PHP;

        $this->assertSame(
            $this->collect($switchCode)->methods[0]->cyclomaticComplexity,
            $this->collect($matchCode)->methods[0]->cyclomaticComplexity,
        );
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
        $analysisNodeCollector  = new AnalysisNodeCollector($namespaceLayerResolver);
        $classMethod            = new ClassMethod('orphan');

        $analysisNodeCollector->setCurrentFile('/fake/path/Foo.php');

        $analysisNodeCollector->enterNode($classMethod);
        $analysisNodeCollector->leaveNode($classMethod);

        $this->assertSame([], $analysisNodeCollector->getClassNodes());
    }
}
