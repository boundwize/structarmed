<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util\PhpParser;

use Boundwize\StructArmed\Util\PhpParser\ObjectBoundAnonymousFunctions;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(ObjectBoundAnonymousFunctions::class)]
final class ObjectBoundAnonymousFunctionsTest extends TestCase
{
    /** @param list<bool> $expected Per anonymous function, in source order */
    #[DataProvider('bindingProvider')]
    public function testRecordsObjectBoundAnonymousFunctions(string $code, array $expected): void
    {
        $this->assertSame($expected, $this->objectBound($code));
    }

    /** @return iterable<string, array{string, list<bool>}> */
    public static function bindingProvider(): iterable
    {
        yield 'direct bindTo' => ['(fn () => 1)->bindTo(new stdClass());', [true]];
        yield 'assigned then bindTo' => ['$callback = fn () => 1; $callback->bindTo(new stdClass());', [true]];
        yield 'assigned then nullsafe bindTo' => [
            '$callback = fn () => 1; $callback?->bindTo(new stdClass());',
            [true],
        ];
        yield 'assigned then bindTo with named argument' => [
            '$callback = function () {}; $bound = $callback->bindTo(newThis: new stdClass());',
            [true],
        ];
        yield 'assigned then call' => ['$callback = fn () => 1; $callback->call(new stdClass());', [true]];
        yield 'assigned then Closure::bind' => [
            '$callback = fn () => 1; ' . \Closure::class . '::bind($callback, new stdClass());',
            [true],
        ];
        yield 'assigned then Closure::bind with named arguments' => [
            '$callback = fn () => 1; ' . \Closure::class . '::bind(newThis: new stdClass(), closure: $callback);',
            [true],
        ];
        yield 'assigned then bindTo null' => ['$callback = fn () => 1; $callback->bindTo(null, Foo::class);', [false]];
        yield 'assigned then Closure::bind null' => [
            '$callback = fn () => 1; ' . \Closure::class . '::bind($callback, null, Foo::class);',
            [false],
        ];
        yield 'assigned and never bound' => ['$callback = fn () => 1; $callback();', [false]];
        yield 'unrelated method on the variable' => ['$callback = fn () => 1; $callback->__invoke();', [false]];
        yield 'dynamic method name on the variable' => [
            '$callback = fn () => 1; $callback->$method(new stdClass());',
            [false],
        ];
        yield 'assigned to a property' => [
            '$this->callback = fn () => 1; $this->callback->bindTo(new stdClass());',
            [false],
        ];
        yield 'binding another variable' => [
            '$callback = fn () => 1; $service->bindTo(new stdClass());',
            [false],
        ];
        yield 'binding before assignment' => [
            '$callback->bindTo(new stdClass()); $callback = fn () => 1;',
            [false],
        ];
        yield 'two variables, one bound' => [
            '$first = fn () => 1; $second = fn () => 2; $second->bindTo(new stdClass());',
            [false, true],
        ];
        yield 'reassigned to another anonymous function' => [
            '$callback = fn () => 1; $callback = fn () => 2; $callback->bindTo(new stdClass());',
            [false, true],
        ];
        yield 'bound, then reassigned' => [
            '$callback = fn () => 1; $callback->bindTo(new stdClass()); $callback = fn () => 2;',
            [true, false],
        ];
        yield 'reassigned to an unknown value' => [
            '$callback = fn () => 1; $callback = make(); $callback->bindTo(new stdClass());',
            [false],
        ];
        yield 'conditionally reassigned' => [
            '$callback = fn () => 1; if ($x) { $callback = fn () => 2; } $callback->bindTo(new stdClass());',
            [true, true],
        ];
        yield 'conditionally reassigned to an unknown value' => [
            '$callback = fn () => 1; if ($x) { $callback = make(); } $callback->bindTo(new stdClass());',
            [true],
        ];
        yield 'reassigned to an unknown value inside try' => [
            '$callback = fn () => 1; try { $callback = make(); } catch (E $e) {} $callback->bindTo(new stdClass());',
            [true],
        ];
        yield 'reassigned after the binding inside a loop' => [
            '$callback = fn () => 1;'
                . ' foreach ($items as $item) { $callback->bindTo(new stdClass()); $callback = fn () => 2; }',
            [true, true],
        ];
        yield 'assigned with ??=' => ['$callback ??= fn () => 1; $callback->bindTo(new stdClass());', [true]];
        yield 'same variable in separate functions' => [
            'function first(): void { $callback = fn () => 1; }'
                . ' function second(object $o): void { $callback = fn () => 2; $callback->bindTo($o); }',
            [false, true],
        ];
        yield 'same variable in separate methods' => [
            'final class C {'
                . ' public function first(object $o): void { $callback->bindTo($o); }'
                . ' public function second(): void { $callback = fn () => 1; } }',
            [false],
        ];
        yield 'bound inside an arrow function' => [
            '$callback = fn () => 1; $bind = fn (object $o) => $callback->bindTo($o);',
            [true, false],
        ];
        yield 'bound inside a closure importing it by value' => [
            '$callback = fn () => 1;'
                . ' $bind = function (object $o) use ($callback) { $callback->bindTo($o); };'
                . ' $callback = fn () => 2;',
            [true, false, false],
        ];
        yield 'bound inside a closure importing it by reference' => [
            '$bind = function (object $o) use (&$callback) { $callback->bindTo($o); }; $callback = fn () => 1;',
            [false, true],
        ];
        yield 'bound inside a closure not importing it' => [
            '$callback = fn () => 1; $bind = function (object $o) { $callback->bindTo($o); };',
            [false, false],
        ];
        yield 'arrow function parameter shadowing an outer variable' => [
            '$callback = fn () => 1;'
                . ' $bind = fn (Closure $callback, object $object) => $callback->bindTo($object);',
            [false, false],
        ];
        yield 'nested arrow function reading an arrow function parameter' => [
            '$callback = fn () => 1;'
                . ' $bind = fn (Closure $callback) => fn (object $object) => $callback->bindTo($object);',
            [false, false, false],
        ];
        yield 'variable assigned inside an arrow function' => [
            '$callback = fn () => 1; $reset = fn () => $callback = null; $callback->bindTo(new stdClass());',
            [true, false],
        ];
    }

    public function testResetForgetsTheFile(): void
    {
        $statements           = $this->parse('$callback = fn () => 1; $callback->bindTo(new stdClass());');
        $objectBoundFunctions = new ObjectBoundAnonymousFunctions();
        [$anonymousFunction]  = $this->anonymousFunctions($statements);

        (new NodeTraverser($objectBoundFunctions))->traverse($statements);
        $this->assertTrue($objectBoundFunctions->isObjectBound($anonymousFunction));

        $objectBoundFunctions->reset();
        $this->assertFalse($objectBoundFunctions->isObjectBound($anonymousFunction));
    }

    public function testUnmatchedLeaveKeepsTheFileScope(): void
    {
        $objectBoundFunctions = new ObjectBoundAnonymousFunctions();
        $statements           = $this->parse('$callback = fn () => 1; $callback->bindTo(new stdClass());');
        [$anonymousFunction]  = $this->anonymousFunctions($statements);

        $objectBoundFunctions->leaveFunctionLike();
        (new NodeTraverser($objectBoundFunctions))->traverse($statements);

        $this->assertTrue($objectBoundFunctions->isObjectBound($anonymousFunction));
    }

    /** @return list<bool> */
    private function objectBound(string $code): array
    {
        $statements           = $this->parse($code);
        $objectBoundFunctions = new ObjectBoundAnonymousFunctions();

        (new NodeTraverser(new NameResolver(), $objectBoundFunctions))->traverse($statements);

        return array_map(
            $objectBoundFunctions->isObjectBound(...),
            $this->anonymousFunctions($statements)
        );
    }

    /** @return array<Node\Stmt> */
    private function parse(string $code): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $code);
        $this->assertIsArray($statements);

        return $statements;
    }

    /**
     * @param array<Node\Stmt> $statements
     * @return list<Closure|ArrowFunction>
     */
    private function anonymousFunctions(array $statements): array
    {
        /** @var list<Closure|ArrowFunction> $anonymousFunctions */
        $anonymousFunctions = (new NodeFinder())->find(
            $statements,
            static fn (Node $node): bool => $node instanceof Closure || $node instanceof ArrowFunction
        );

        return $anonymousFunctions;
    }
}
