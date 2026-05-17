<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;

final class ComplexityCollectorVisitor extends NodeVisitorAbstract
{
    /** @var array<string, int> */
    public array $complexity = [];

    private ?string $currentMethod = null;

    private int $count = 0;

    private int $depth = 0;

    public function enterNode(Node $node): null
    {
        if ($node instanceof ClassMethod) {
            if (++$this->depth === 1) {
                $this->currentMethod = (string) $node->name;
                $this->count         = 0;
            }
        } elseif (
            $this->depth === 1 && (
            $node instanceof If_
            || $node instanceof ElseIf_
            || $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof Case_
            || $node instanceof Catch_
            || $node instanceof Ternary
            || $node instanceof BooleanAnd
            || $node instanceof BooleanOr
            || $node instanceof LogicalAnd
            || $node instanceof LogicalOr
            || $node instanceof Match_
            )
        ) {
            $this->count++;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassMethod && $this->depth-- === 1 && $this->currentMethod !== null) {
            $this->complexity[$this->currentMethod] = 1 + $this->count;
            $this->currentMethod                    = null;
        }

        return null;
    }
}
