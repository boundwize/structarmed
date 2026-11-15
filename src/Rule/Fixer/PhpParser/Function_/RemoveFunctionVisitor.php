<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\Function_;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Namespace_ as NamespaceMagicConst;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

use function ltrim;
use function strcasecmp;

/**
 * Removes a function declaration, and the `if (! function_exists('...'))`
 * guard naming a removed function when removing it leaves that guard with an
 * empty block and no `else` or `elseif`.
 */
final class RemoveFunctionVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $removedFunctionNames = [];

    /** The namespace being traversed, as `__NAMESPACE__` evaluates to: empty in the global namespace */
    private string $currentNamespace = '';

    public function __construct(
        private readonly string $functionName,
    ) {
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = (string) $node->name;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof If_) {
            return $this->isEmptiedFunctionExistsGuard($node) ? NodeVisitor::REMOVE_NODE : null;
        }

        if (! $node instanceof Function_) {
            return null;
        }

        if (! isset($node->namespacedName)) {
            return null;
        }

        if ($node->namespacedName->toString() !== $this->functionName) {
            return null;
        }

        $this->removedFunctionNames[] = $this->functionName;

        return NodeVisitor::REMOVE_NODE;
    }

    private function isEmptiedFunctionExistsGuard(If_ $if): bool
    {
        if ($if->stmts !== [] || $if->elseifs !== [] || $if->else instanceof Node) {
            return false;
        }

        if (! $if->cond instanceof BooleanNot) {
            return false;
        }

        $funcCall = $if->cond->expr;

        if (
            ! $funcCall instanceof FuncCall
            || ! $funcCall->name instanceof Name
            || $funcCall->name->toLowerString() !== 'function_exists'
            || $funcCall->isFirstClassCallable()
        ) {
            return false;
        }

        $guardedFunctionName = $this->guardedFunctionName($funcCall->getArgs()[0]->value ?? null);

        if ($guardedFunctionName === null) {
            return false;
        }

        foreach ($this->removedFunctionNames as $removedFunctionName) {
            if (strcasecmp($guardedFunctionName, $removedFunctionName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The function a guard argument names: a string literal, or
     * `__NAMESPACE__ . '\name'`, which spells a function of the namespace
     * being traversed. Null for any other expression.
     */
    private function guardedFunctionName(?Expr $expr): ?string
    {
        if ($expr instanceof Concat && $expr->left instanceof NamespaceMagicConst && $expr->right instanceof String_) {
            return ltrim($this->currentNamespace . $expr->right->value, '\\');
        }

        return $expr instanceof String_ ? ltrim($expr->value, '\\') : null;
    }
}
