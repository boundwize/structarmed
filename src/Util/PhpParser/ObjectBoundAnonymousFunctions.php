<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Util\PhpParser;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Coalesce as AssignCoalesce;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Label;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;

use function is_string;
use function spl_object_id;
use function strcasecmp;

/**
 * Records the anonymous functions of one file that are bound to an object
 * through the Closure APIs (`bindTo()`, `call()`, `Closure::bind()`), either
 * directly or through a local variable they were assigned to. Static
 * anonymous functions cannot be object-bound.
 *
 * Each function-like is a variable scope, plus one for the file. An arrow
 * function reads every enclosing variable by value at creation, a closure the
 * ones it imports with `use`, by value or by reference.
 *
 * No control flow is modelled: in straight code, a variable holds the
 * anonymous function assigned to it last; once the scope has branched (a
 * condition, loop, `try`, short-circuit, or `goto` label), every anonymous
 * function it was ever assigned may be the one bound, including those
 * assigned after the binding (a loop runs it again).
 *
 * A NodeVisitor for the fixer; AnalysisNodeCollector calls the entry points
 * from its own traversal instead.
 */
final class ObjectBoundAnonymousFunctions extends NodeVisitorAbstract
{
    /**
     * Node classes whose children may run conditionally or repeatedly.
     *
     * @var array<class-string<Node>, true>
     */
    public const CONTROL_FLOW_NODES = [
        If_::class                   => true,
        For_::class                  => true,
        Foreach_::class              => true,
        While_::class                => true,
        Do_::class                   => true,
        Switch_::class               => true,
        TryCatch::class              => true,
        Label::class                 => true,
        Match_::class                => true,
        Ternary::class               => true,
        BooleanAnd::class            => true,
        BooleanOr::class             => true,
        LogicalAnd::class            => true,
        LogicalOr::class             => true,
        Coalesce::class              => true,
        AssignCoalesce::class        => true,
        NullsafeMethodCall::class    => true,
        NullsafePropertyFetch::class => true,
    ];

    /** @var array<int, true> Object-bound anonymous functions, keyed by object id */
    private array $objectBound = [];

    /** Index of the innermost scope; the file scope is 0. */
    private int $scope = 0;

    /**
     * Per scope: the anonymous functions each variable may hold; none for
     * a variable whose value is unknown (an arrow function's parameter, or
     * one assigned something else).
     *
     * @var array<int, array<string, list<Closure|ArrowFunction>>>
     */
    private array $variables = [[]];

    /**
     * Per scope: variables bound where an anonymous function assigned later
     * may still reach the binding.
     *
     * @var array<int, array<string, true>>
     */
    private array $laterAssignmentsBound = [[]];

    /** @var array<int, bool> Per scope: whether control flow has branched */
    private array $branched = [false];

    /**
     * Per scope: the enclosing variables it reads, as name => by reference;
     * null for an arrow function, which reads them all by value.
     *
     * @var array<int, array<string, bool>|null>
     */
    private array $captures = [[]];

    /** @param Node[] $nodes */
    public function beforeTraverse(array $nodes): null
    {
        $this->reset();

        return null;
    }

    public function enterNode(Node $node): null
    {
        if (isset(self::CONTROL_FLOW_NODES[$node::class])) {
            $this->branch();
        } elseif ($node instanceof FunctionLike) {
            $this->enterFunctionLike($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof FunctionLike) {
            $this->leaveFunctionLike();
        } elseif ($node instanceof Assign || $node instanceof AssignCoalesce) {
            $this->assign($node);
        } elseif (
            $node instanceof StaticCall
            || $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
        ) {
            $this->bindingCall($node);
        }

        return null;
    }

    public function reset(): void
    {
        $this->objectBound           = [];
        $this->scope                 = 0;
        $this->variables             = [[]];
        $this->laterAssignmentsBound = [[]];
        $this->branched              = [false];
        $this->captures              = [[]];
    }

    public function enterFunctionLike(FunctionLike $functionLike): void
    {
        $captures = [];

        if ($functionLike instanceof ArrowFunction) {
            $captures = null;
        } elseif ($functionLike instanceof Closure) {
            foreach ($functionLike->uses as $use) {
                if (is_string($use->var->name)) {
                    $captures[$use->var->name] = $use->byRef;
                }
            }
        }

        $scope = ++$this->scope;

        $this->variables[$scope]             = [];
        $this->laterAssignmentsBound[$scope] = [];
        $this->branched[$scope]              = false;
        $this->captures[$scope]              = $captures;

        if (! $functionLike instanceof ArrowFunction) {
            return;
        }

        // A parameter shadows the enclosing variable of the same name, which
        // an arrow function would otherwise read.
        foreach ($functionLike->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $this->variables[$scope][$param->var->name] = [];
            }
        }
    }

    public function leaveFunctionLike(): void
    {
        // The file scope stays, even on an unmatched leave.
        if ($this->scope === 0) {
            return;
        }

        unset(
            $this->variables[$this->scope],
            $this->laterAssignmentsBound[$this->scope],
            $this->branched[$this->scope],
            $this->captures[$this->scope],
        );

        $this->scope--;
    }

    public function branch(): void
    {
        $this->branched[$this->scope] = true;
    }

    /**
     * Called on leaving the assignment, once an assigned anonymous function
     * has been traversed.
     */
    public function assign(Assign|AssignCoalesce $assign): void
    {
        if (! $assign->var instanceof Variable || ! is_string($assign->var->name)) {
            return;
        }

        $name  = $assign->var->name;
        $scope = $this->scope;
        $expr  = $assign->expr;

        if (! $expr instanceof Closure && ! $expr instanceof ArrowFunction) {
            // Overwritten in straight code, the variable no longer holds what
            // it was assigned before, nor what an enclosing scope gave it;
            // after a branch, it still may.
            if (! $this->branched[$scope]) {
                $this->variables[$scope][$name] = [];
            }

            return;
        }

        if (isset($this->laterAssignmentsBound[$scope][$name])) {
            $this->objectBound[spl_object_id($expr)] = true;
        }

        if ($this->branched[$scope]) {
            $this->variables[$scope][$name][] = $expr;
        } else {
            $this->variables[$scope][$name] = [$expr];
        }
    }

    public function bindingCall(StaticCall|MethodCall|NullsafeMethodCall $call): void
    {
        $bound = $call instanceof StaticCall
            ? $this->boundByStaticCall($call)
            : $this->boundByMethodCall($call);

        if ($bound instanceof Closure || $bound instanceof ArrowFunction) {
            $this->objectBound[spl_object_id($bound)] = true;
        } elseif ($bound instanceof Variable && is_string($bound->name)) {
            $this->bindVariable($bound->name);
        }
    }

    public function isObjectBound(FunctionLike $functionLike): bool
    {
        return isset($this->objectBound[spl_object_id($functionLike)]);
    }

    private function bindVariable(string $name): void
    {
        $scope       = $this->scope;
        $byReference = false;

        // A variable the scope does not hold itself is resolved in the scope
        // it was captured from.
        while ($scope > 0 && ! isset($this->variables[$scope][$name])) {
            $captures = $this->captures[$scope];

            if ($captures !== null) {
                if (! isset($captures[$name])) {
                    break;
                }

                $byReference = $byReference || $captures[$name];
            }

            $scope--;
        }

        foreach ($this->variables[$scope][$name] ?? [] as $anonymousFunction) {
            $this->objectBound[spl_object_id($anonymousFunction)] = true;
        }

        // A reference sees every later assignment, as may a branched scope.
        if ($byReference || $this->branched[$scope]) {
            $this->laterAssignmentsBound[$scope][$name] = true;
        }
    }

    private function boundByStaticCall(StaticCall $staticCall): ?Expr
    {
        if (
            ! $staticCall->name instanceof Identifier
            || $staticCall->name->toLowerString() !== 'bind'
            || ! $staticCall->class instanceof Name
            || ! $this->nameResolvesToClosure($staticCall->class)
        ) {
            return null;
        }

        $closureArg = $staticCall->getArg('closure', 0);

        return $closureArg instanceof Arg && ! $this->isExplicitNull($staticCall->getArg('newThis', 1))
            ? $closureArg->value
            : null;
    }

    private function boundByMethodCall(MethodCall|NullsafeMethodCall $methodCall): ?Expr
    {
        $receiver = $methodCall->var;

        if (
            (
                ! $receiver instanceof Closure
                && ! $receiver instanceof ArrowFunction
                && ! $receiver instanceof Variable
            )
            || ! $methodCall->name instanceof Identifier
        ) {
            return null;
        }

        $methodName = $methodCall->name->toLowerString();

        if ($methodName === 'call') {
            return $receiver;
        }

        return $methodName === 'bindto' && ! $this->isExplicitNull($methodCall->getArg('newThis', 0))
            ? $receiver
            : null;
    }

    private function nameResolvesToClosure(Name $name): bool
    {
        $resolvedName = $name->getAttribute('resolvedName');

        if (! $resolvedName instanceof Name) {
            $resolvedName = $name->getAttribute('namespacedName');
        }

        return strcasecmp(
            $resolvedName instanceof Name ? $resolvedName->toString() : $name->toString(),
            'Closure'
        ) === 0;
    }

    private function isExplicitNull(?Arg $arg): bool
    {
        return $arg instanceof Arg
            && $arg->value instanceof ConstFetch
            && $arg->value->name->toLowerString() === 'null';
    }
}
