<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\FunctionLike;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike\AddStaticAnonymousFunctionVisitor;
use Boundwize\StructArmed\Util\PhpParser\ObjectBoundAnonymousFunction;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddStaticAnonymousFunctionVisitor::class)]
#[CoversClass(ObjectBoundAnonymousFunction::class)]
final class AddStaticAnonymousFunctionVisitorTest extends TestCase
{
    public function testAddsStaticToClosureOnMatchingLine(): void
    {
        $closure = new Closure([], ['startLine' => 12]);

        (new NodeTraverser(new AddStaticAnonymousFunctionVisitor(12)))->traverse([$closure]);

        $this->assertTrue($closure->static);
    }

    public function testAddsStaticToArrowFunctionOnMatchingLine(): void
    {
        $arrowFunction = new ArrowFunction(['expr' => new Int_(1)], ['startLine' => 12]);

        (new NodeTraverser(new AddStaticAnonymousFunctionVisitor(12)))->traverse([$arrowFunction]);

        $this->assertTrue($arrowFunction->static);
    }

    public function testDoesNotChangeClosureOnDifferentLine(): void
    {
        $closure = new Closure([], ['startLine' => 13]);

        (new NodeTraverser(new AddStaticAnonymousFunctionVisitor(12)))->traverse([$closure]);

        $this->assertFalse($closure->static);
    }

    public function testDoesNotChangeAlreadyStaticClosure(): void
    {
        $closure                           = new Closure(['static' => true], ['startLine' => 12]);
        $addStaticAnonymousFunctionVisitor = new AddStaticAnonymousFunctionVisitor(12);

        $this->assertNotInstanceOf(Node::class, $addStaticAnonymousFunctionVisitor->enterNode($closure));
        $this->assertTrue($closure->static);
    }

    public function testDoesNotChangeAnonymousFunctionReadingThisOnTheSameLine(): void
    {
        // `[fn () => 1, fn () => $this->value]` on one line: only the first is a violation.
        $plain     = new ArrowFunction(['expr' => new Int_(1)], ['startLine' => 12]);
        $usingThis = new ArrowFunction(
            ['expr' => new PropertyFetch(new Variable('this'), new Identifier('value'))],
            ['startLine' => 12]
        );

        (new NodeTraverser(new AddStaticAnonymousFunctionVisitor(12)))->traverse([$plain, $usingThis]);

        $this->assertTrue($plain->static);
        $this->assertFalse($usingThis->static);
    }

    public function testDoesNotChangeClosureWhoseNestedClosureReadsThis(): void
    {
        $inner = new Closure(['stmts' => [new Return_(new Variable('this'))]], ['startLine' => 12]);
        $outer = new Closure(['stmts' => [new Return_($inner)]], ['startLine' => 12]);

        (new NodeTraverser(new AddStaticAnonymousFunctionVisitor(12)))->traverse([$outer]);

        $this->assertFalse($outer->static);
        $this->assertFalse($inner->static);
    }

    public function testChangesClosureWhoseNestedAnonymousClassReadsThis(): void
    {
        $anonymousClass = new Class_(null, [
            'stmts' => [new ClassMethod('run', ['stmts' => [new Return_(new Variable('this'))]])],
        ]);
        $closure        = new Closure(['stmts' => [new Return_(new New_($anonymousClass))]], ['startLine' => 12]);

        (new NodeTraverser(new AddStaticAnonymousFunctionVisitor(12)))->traverse([$closure]);

        $this->assertTrue($closure->static);
    }

    #[DataProvider('objectBoundAnonymousFunctionProvider')]
    public function testDoesNotChangeDirectlyObjectBoundAnonymousFunction(string $expression): void
    {
        [$statements, $anonymousFunction] = $this->parseExpression($expression);

        (new NodeTraverser(new AddStaticAnonymousFunctionVisitor(1)))->traverse($statements);

        $this->assertFalse($anonymousFunction->static);
    }

    /** @return iterable<string, array{string}> */
    public static function objectBoundAnonymousFunctionProvider(): iterable
    {
        yield 'Closure bind with positional object' => [
            \Closure::class . '::bind(function (): void { foo(); }, new stdClass())',
        ];
        yield 'Closure bind with reordered named arguments' => [
            \Closure::class . '::bind(newThis: new stdClass(), closure: function (): void { foo(); })',
        ];
        yield 'arrow bind with positional object' => [\Closure::class . '::bind(fn () => foo(), new stdClass())'];
        yield 'arrow bind with reordered named arguments' => [
            \Closure::class . '::bind(newThis: new stdClass(), closure: fn () => foo())',
        ];
        yield 'closure bindTo with positional object' => [
            '(function (): void { foo(); })->bindTo(new stdClass())',
        ];
        yield 'closure bindTo with named object' => [
            '(function (): void { foo(); })->bindTo(newThis: new stdClass())',
        ];
        yield 'arrow bindTo with positional object' => ['(fn () => foo())->bindTo(new stdClass())'];
        yield 'arrow bindTo with named object' => ['(fn () => foo())->bindTo(newThis: new stdClass())'];
        yield 'closure call with positional object' => [
            '(function (): void { foo(); })->call(new stdClass())',
        ];
        yield 'closure call with named object' => [
            '(function (): void { foo(); })->call(newThis: new stdClass())',
        ];
        yield 'arrow call with positional object' => ['(fn () => foo())->call(new stdClass())'];
        yield 'arrow call with named object' => ['(fn () => foo())->call(newThis: new stdClass())'];
        yield 'nullsafe closure bindTo with object' => [
            '(function (): void { foo(); })?->bindTo(new stdClass())',
        ];
        yield 'nullsafe arrow bindTo with object' => ['(fn () => foo())?->bindTo(new stdClass())'];
        yield 'nullsafe closure call with object' => [
            '(function (): void { foo(); })?->call(new stdClass())',
        ];
        yield 'nullsafe arrow call with object' => ['(fn () => foo())?->call(new stdClass())'];
    }

    #[DataProvider('scopeOnlyAnonymousFunctionProvider')]
    public function testChangesScopeOnlyBoundAnonymousFunction(string $expression): void
    {
        [$statements, $anonymousFunction] = $this->parseExpression($expression);

        (new NodeTraverser(new AddStaticAnonymousFunctionVisitor(1)))->traverse($statements);

        $this->assertTrue($anonymousFunction->static);
    }

    /** @return iterable<string, array{string}> */
    public static function scopeOnlyAnonymousFunctionProvider(): iterable
    {
        yield 'Closure bind with explicit null' => [
            \Closure::class . '::bind(function (): void { foo(); }, null, Foo::class)',
        ];
        yield 'arrow bind with explicit null' => [\Closure::class . '::bind(fn () => foo(), null, Foo::class)'];
        yield 'Closure bind with named explicit null' => [
            \Closure::class
                . '::bind(newThis: null, closure: function (): void { foo(); }, newScope: Foo::class)',
        ];
        yield 'arrow bind with named explicit null' => [
            \Closure::class . '::bind(newThis: null, closure: fn () => foo(), newScope: Foo::class)',
        ];
        yield 'closure bindTo with explicit null' => [
            '(function (): void { foo(); })->bindTo(null, Foo::class)',
        ];
        yield 'arrow bindTo with explicit null' => ['(fn () => foo())->bindTo(null, Foo::class)'];
        yield 'closure bindTo with named explicit null' => [
            '(function (): void { foo(); })->bindTo(newThis: null, newScope: Foo::class)',
        ];
        yield 'arrow bindTo with named explicit null' => [
            '(fn () => foo())->bindTo(newThis: null, newScope: Foo::class)',
        ];
        yield 'unrelated static bind call' => [
            'Other::bind(function (): void { foo(); }, new stdClass())',
        ];
        yield 'unrelated anonymous function method call' => [
            '(function (): void { foo(); })->__invoke()',
        ];
    }

    public function testDoesNotChangeNonAnonymousFunctionNode(): void
    {
        $addStaticAnonymousFunctionVisitor = new AddStaticAnonymousFunctionVisitor(12);

        $this->assertNotInstanceOf(
            Node::class,
            $addStaticAnonymousFunctionVisitor->enterNode(
                new ClassMethod('save', [], ['startLine' => 12])
            )
        );
    }

    /**
     * @return array{array<Node\Stmt>, Closure|ArrowFunction}
     */
    private function parseExpression(string $expression): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $expression . ';');
        $this->assertIsArray($statements);

        $anonymousFunction = (new NodeFinder())->findFirst(
            $statements,
            static fn (Node $node): bool => $node instanceof Closure || $node instanceof ArrowFunction
        );
        if (! $anonymousFunction instanceof Closure && ! $anonymousFunction instanceof ArrowFunction) {
            self::fail('Expected an anonymous function in the parsed expression');
        }

        return [$statements, $anonymousFunction];
    }
}
