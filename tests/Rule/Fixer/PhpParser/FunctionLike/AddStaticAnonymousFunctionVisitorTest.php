<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\FunctionLike;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike\AddStaticAnonymousFunctionVisitor;
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
use PhpParser\NodeTraverser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddStaticAnonymousFunctionVisitor::class)]
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
}
