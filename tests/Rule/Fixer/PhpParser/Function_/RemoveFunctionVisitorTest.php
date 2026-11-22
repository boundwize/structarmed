<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\Function_;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\Function_\RemoveFunctionVisitor;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Namespace_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_ as NamespaceStmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoveFunctionVisitor::class)]
final class RemoveFunctionVisitorTest extends TestCase
{
    public function testRemovesMatchingFunction(): void
    {
        $function                 = new Function_('unused');
        $function->namespacedName = new Name('App\\unused');

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))
            ->traverse([$function]);

        $this->assertSame([], $statements);
    }

    public function testKeepsNonMatchingFunction(): void
    {
        $function                 = new Function_('used');
        $function->namespacedName = new Name('App\\used');

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))
            ->traverse([$function]);

        $this->assertSame([$function], $statements);
    }

    public function testKeepsFunctionWithoutNamespacedName(): void
    {
        $function = new Function_('unused');

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('unused')))
            ->traverse([$function]);

        $this->assertSame([$function], $statements);
    }

    public function testKeepsAlreadyEmptyFunctionExistsGuard(): void
    {
        $if = $this->functionExistsGuard([]);

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))
            ->traverse([$if]);

        $this->assertSame([$if], $statements);
    }

    public function testKeepsNonFunctionExistsIfEmptiedByRemoval(): void
    {
        $function                 = new Function_('unused');
        $function->namespacedName = new Name('App\\unused');

        $if = new If_(new BooleanNot(new FuncCall(new Name('is_callable'))), [
            'stmts' => [$function],
        ]);

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))
            ->traverse([$if]);

        $this->assertSame([$if], $statements);
        $this->assertSame([], $if->stmts);
    }

    public function testRemovesFunctionExistsGuardNamingRemovedFunction(): void
    {
        $function                 = new Function_('unused');
        $function->namespacedName = new Name('App\\unused');

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))
            ->traverse([$this->functionExistsGuard([$function], '\\App\\Unused')]);

        $this->assertSame([], $statements);
    }

    public function testKeepsFunctionExistsGuardNamingAnotherFunction(): void
    {
        $function                 = new Function_('unused');
        $function->namespacedName = new Name('App\\unused');

        $if = $this->functionExistsGuard([$function], 'App\\other');

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))
            ->traverse([$if]);

        $this->assertSame([$if], $statements);
        $this->assertSame([], $if->stmts);
    }

    public function testKeepsNonNegatedFunctionExistsIfEmptiedByRemoval(): void
    {
        $function                 = new Function_('unused');
        $function->namespacedName = new Name('App\\unused');

        $if = new If_(
            new FuncCall(new Name('function_exists'), [new Arg(new String_('App\\unused'))]),
            ['stmts' => [$function]]
        );

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))
            ->traverse([$if]);

        $this->assertSame([$if], $statements);
    }

    public function testKeepsFunctionExistsGuardWithNonLiteralArgument(): void
    {
        $function                 = new Function_('unused');
        $function->namespacedName = new Name('App\\unused');

        $if = new If_(
            new BooleanNot(new FuncCall(new Name('function_exists'), [new Arg(new Variable('name'))])),
            ['stmts' => [$function]]
        );

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))
            ->traverse([$if]);

        $this->assertSame([$if], $statements);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function partialFunctionExistsApplicationProvider(): iterable
    {
        yield 'placeholder' => [
            <<<'PHP'
                <?php

                namespace App;

                if (! function_exists(?)) {
                    function unused(): void {}
                }
                PHP,
        ];

        yield 'named placeholder' => [
            <<<'PHP'
                <?php

                namespace App;

                if (! function_exists(function: ?)) {
                    function unused(): void {}
                }
                PHP,
        ];

        yield 'bound name before variadic placeholder' => [
            <<<'PHP'
                <?php

                namespace App;

                if (! function_exists('App\unused', ...)) {
                    function unused(): void {}
                }
                PHP,
        ];

        yield 'first-class callable' => [
            <<<'PHP'
                <?php

                namespace App;

                if (! function_exists(...)) {
                    function unused(): void {}
                }
                PHP,
        ];
    }

    #[DataProvider('partialFunctionExistsApplicationProvider')]
    public function testKeepsPartiallyAppliedFunctionExistsIfEmptiedByRemoval(string $code): void
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);

        $statements = (new NodeTraverser(new NameResolver(), new RemoveFunctionVisitor('App\\unused')))
            ->traverse($ast ?? []);

        $namespace = $statements[0] ?? null;

        $this->assertInstanceOf(NamespaceStmt::class, $namespace);

        // A partial application yields a closure instead of running the
        // check, so the emptied block is no guard of the removed function.
        $this->assertCount(1, $namespace->stmts);
        $this->assertInstanceOf(If_::class, $namespace->stmts[0]);
        $this->assertSame([], $namespace->stmts[0]->stmts);
    }

    public function testRemovesNamespaceConcatFunctionExistsGuardNamingRemovedFunction(): void
    {
        $function                 = new Function_('unused');
        $function->namespacedName = new Name('App\\unused');

        $namespace = new NamespaceStmt(new Name('App'), [$this->namespaceConcatGuard([$function])]);

        (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))->traverse([$namespace]);

        $this->assertSame([], $namespace->stmts);
    }

    public function testKeepsNamespaceConcatFunctionExistsGuardOfAnotherNamespace(): void
    {
        $function                 = new Function_('unused');
        $function->namespacedName = new Name('App\\unused');

        $if        = $this->namespaceConcatGuard([$function]);
        $namespace = new NamespaceStmt(new Name('Other'), [$if]);

        (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))->traverse([$namespace]);

        $this->assertSame([$if], $namespace->stmts);
        $this->assertSame([], $if->stmts);
    }

    /** @param list<Function_> $stmts */
    private function namespaceConcatGuard(array $stmts): If_
    {
        return new If_(
            new BooleanNot(new FuncCall(new Name('function_exists'), [
                new Arg(new Concat(new Namespace_(), new String_('\\unused'))),
            ])),
            ['stmts' => $stmts]
        );
    }

    /** @param list<Function_> $stmts */
    private function functionExistsGuard(array $stmts, string $guardedFunctionName = 'App\\unused'): If_
    {
        return new If_(
            new BooleanNot(new FuncCall(new Name('function_exists'), [new Arg(new String_($guardedFunctionName))])),
            ['stmts' => $stmts]
        );
    }

    public function testDoesNotRemoveNonFunctionNode(): void
    {
        $removeFunctionVisitor = new RemoveFunctionVisitor('App\\unused');

        $this->assertNull($removeFunctionVisitor->leaveNode(new ClassMethod('unused')));
    }
}
