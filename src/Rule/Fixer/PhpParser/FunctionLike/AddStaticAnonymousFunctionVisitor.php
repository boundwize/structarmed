<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike;

use Boundwize\StructArmed\Util\PhpParser\ObjectBoundAnonymousFunction;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

use function array_pop;
use function end;
use function in_array;

/**
 * Adds the `static` modifier to the anonymous functions starting on the given
 * line that neither read `$this`, call a parent method from a non-static
 * method, nor require object binding.
 *
 * An anonymous function has no name, so its start line is the only identity
 * a violation can carry, and several may start on one line. Re-applying the
 * rule's own condition here — instead of trusting the line alone — means
 * every function this visitor changes is one the rule flags, so an unsafe
 * anonymous function sharing the line with a flagged one is left untouched.
 */
final class AddStaticAnonymousFunctionVisitor extends NodeVisitorAbstract
{
    /** @var list<Closure|ArrowFunction> */
    private array $objectBoundAnonymousFunctions = [];

    /** @var list<bool> whether each method being entered is static, innermost last */
    private array $activeMethodStaticFlags = [];

    public function __construct(
        private readonly int $line,
    ) {
    }

    /** @param Node[] $nodes */
    public function beforeTraverse(array $nodes): null
    {
        $this->objectBoundAnonymousFunctions = [];
        $this->activeMethodStaticFlags       = [];

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof ClassMethod) {
            $this->activeMethodStaticFlags[] = $node->isStatic();

            return null;
        }

        if (
            $node instanceof StaticCall
            || $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
        ) {
            $anonymousFunction = $node instanceof StaticCall
                ? ObjectBoundAnonymousFunction::fromStaticCall($node)
                : ObjectBoundAnonymousFunction::fromMethodCall($node);

            if ($anonymousFunction instanceof Closure || $anonymousFunction instanceof ArrowFunction) {
                $this->objectBoundAnonymousFunctions[] = $anonymousFunction;
            }

            return null;
        }

        if (! $node instanceof Closure && ! $node instanceof ArrowFunction) {
            return null;
        }

        if ($node->static || $node->getStartLine() !== $this->line) {
            return null;
        }

        if ($this->requiresObjectBinding($node) || $this->usesThis($node)) {
            return null;
        }

        $node->static = true;

        return $node;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassMethod) {
            array_pop($this->activeMethodStaticFlags);
        }

        return null;
    }

    private function requiresObjectBinding(Closure|ArrowFunction $anonymousFunction): bool
    {
        return in_array($anonymousFunction, $this->objectBoundAnonymousFunctions, true);
    }

    /**
     * Whether the body reads `$this`, including through nested anonymous
     * functions.
     * `$this` inside a nested class-like body (an anonymous class, or a class,
     * trait, or enum declared in the body) is that class-like's own, so
     * class-likes are not descended into.
     * A `parent::method()` call forwards `$this` to a non-static parent
     * method, so it counts unless the enclosing method is static: with no
     * `$this` to forward, the parent method called there is static too.
     */
    private function usesThis(Closure|ArrowFunction $anonymousFunction): bool
    {
        $parentCallForwardsThis = ! end($this->activeMethodStaticFlags);
        $thisFinder             = new class ($parentCallForwardsThis) extends NodeVisitorAbstract {
            public bool $found = false;

            public function __construct(
                private readonly bool $parentCallForwardsThis,
            ) {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if (
                    ($node instanceof Variable && $node->name === 'this')
                    || ($this->parentCallForwardsThis
                        && $node instanceof StaticCall
                        && $node->class instanceof Name
                        && $node->class->toLowerString() === 'parent')
                ) {
                    $this->found = true;

                    return NodeVisitor::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        (new NodeTraverser($thisFinder))->traverse([$anonymousFunction]);

        return $thisFinder->found;
    }
}
