<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\FunctionLike;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike\AddStaticAnonymousFunctionVisitor;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\ClassMethod;
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

    public function testDoesNotChangeNonAnonymousFunctionNode(): void
    {
        $addStaticAnonymousFunctionVisitor = new AddStaticAnonymousFunctionVisitor(12);

        $this->assertNotInstanceOf(Node::class, $addStaticAnonymousFunctionVisitor->enterNode(new ClassMethod('save', [], ['startLine' => 12])));
    }
}
