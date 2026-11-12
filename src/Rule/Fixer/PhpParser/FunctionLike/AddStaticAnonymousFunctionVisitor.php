<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike;

use Boundwize\StructArmed\Util\PhpParser\ObjectBoundAnonymousFunctions;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

use function array_fill_keys;

/**
 * Adds the `static` modifier to the anonymous functions starting on the given
 * lines that neither read `$this` nor require object binding.
 *
 * An anonymous function has no name, so its start line is the only identity
 * a violation can carry, and several may start on one line. Re-applying the
 * rule's own condition here — instead of trusting the line alone — means
 * every function this visitor changes is one the rule flags, so an unsafe
 * anonymous function sharing the line with a flagged one is left untouched.
 * A binding through a variable may follow the anonymous function, so the
 * bindings of the whole file are recorded before the traversal starts.
 */
final class AddStaticAnonymousFunctionVisitor extends NodeVisitorAbstract
{
    private readonly ObjectBoundAnonymousFunctions $objectBoundAnonymousFunctions;

    /** @var array<int, true> */
    private readonly array $lines;

    public function __construct(int ...$lines)
    {
        $this->lines                         = array_fill_keys($lines, true);
        $this->objectBoundAnonymousFunctions = new ObjectBoundAnonymousFunctions();
    }

    /** @param Node[] $nodes */
    public function beforeTraverse(array $nodes): null
    {
        (new NodeTraverser($this->objectBoundAnonymousFunctions))->traverse($nodes);

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof Closure && ! $node instanceof ArrowFunction) {
            return null;
        }

        if ($node->static || ! isset($this->lines[$node->getStartLine()])) {
            return null;
        }

        if ($this->objectBoundAnonymousFunctions->isObjectBound($node) || $this->usesThis($node)) {
            return null;
        }

        $node->static = true;

        return $node;
    }

    /**
     * Whether the body reads `$this`, including through nested anonymous
     * functions.
     * `$this` inside a nested class-like body (an anonymous class, or a class,
     * trait, or enum declared in the body) is that class-like's own, so
     * class-likes are not descended into.
     */
    private function usesThis(Closure|ArrowFunction $anonymousFunction): bool
    {
        $thisFinder = new class extends NodeVisitorAbstract {
            public bool $found = false;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Variable && $node->name === 'this') {
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
