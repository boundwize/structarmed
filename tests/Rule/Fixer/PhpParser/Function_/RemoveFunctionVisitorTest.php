<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\Function_;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\Function_\RemoveFunctionVisitor;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Namespace_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testRemovesMatchingFunctionCaseInsensitively(): void
    {
        $function                 = new Function_('unusedHelper');
        $function->namespacedName = new Name('App\\unusedHelper');

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('app\\UNUSEDHELPER')))
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
            new BooleanNot(new FuncCall(new Name('function_exists'), [
                new Arg(new Concat(new Namespace_(), new String_('\\unused'))),
            ])),
            ['stmts' => [$function]]
        );

        $statements = (new NodeTraverser(new RemoveFunctionVisitor('App\\unused')))
            ->traverse([$if]);

        $this->assertSame([$if], $statements);
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
