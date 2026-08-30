<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Adds the `static` modifier to the closures and arrow functions starting on
 * the given line that do not read `$this`.
 *
 * An anonymous function has no name, so its start line is the only identity
 * a violation can carry, and several may start on one line. Re-applying the
 * rule's own condition here — instead of trusting the line alone — means
 * every function this visitor changes is one the rule flags, so a `$this`
 * -reading closure sharing the line with a flagged one is left untouched.
 */
final class AddStaticAnonymousFunctionVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly int $line,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof Closure && ! $node instanceof ArrowFunction) {
            return null;
        }

        if ($node->static || $node->getStartLine() !== $this->line) {
            return null;
        }

        if ($this->usesThis($node)) {
            return null;
        }

        $node->static = true;

        return $node;
    }

    /**
     * Whether the body reads `$this`, including through nested closures.
     * `$this` inside a nested anonymous class body is that class's own, so
     * anonymous classes are not descended into.
     */
    private function usesThis(Closure|ArrowFunction $anonymousFunction): bool
    {
        $thisFinder = new class extends NodeVisitorAbstract {
            public bool $found = false;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Class_) {
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
