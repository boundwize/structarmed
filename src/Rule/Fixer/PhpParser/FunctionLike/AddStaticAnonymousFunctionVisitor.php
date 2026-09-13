<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike;

use Boundwize\StructArmed\Util\PhpParser\ObjectBoundAnonymousFunction;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

use function spl_object_id;

/**
 * Adds the `static` modifier to the anonymous functions starting on the given
 * line that neither read `$this` nor require object binding.
 *
 * An anonymous function has no name, so its start line is the only identity
 * a violation can carry, and several may start on one line. Re-applying the
 * rule's own condition here — instead of trusting the line alone — means
 * every function this visitor changes is one the rule flags, so an unsafe
 * anonymous function sharing the line with a flagged one is left untouched.
 */
final class AddStaticAnonymousFunctionVisitor extends NodeVisitorAbstract
{
    /** @var array<int, true> */
    private array $objectBoundAnonymousFunctions = [];

    public function __construct(
        private readonly int $line,
    ) {
    }

    /** @param Node[] $nodes */
    public function beforeTraverse(array $nodes): null
    {
        $this->objectBoundAnonymousFunctions = [];

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof StaticCall || $node instanceof MethodCall) {
            $anonymousFunction = $node instanceof StaticCall
                ? ObjectBoundAnonymousFunction::fromStaticCall($node)
                : ObjectBoundAnonymousFunction::fromMethodCall($node);

            if ($anonymousFunction instanceof Closure || $anonymousFunction instanceof ArrowFunction) {
                $this->objectBoundAnonymousFunctions[spl_object_id($anonymousFunction)] = true;
            }

            return null;
        }

        if (! $node instanceof Closure && ! $node instanceof ArrowFunction) {
            return null;
        }

        if ($node->static || $node->getStartLine() !== $this->line) {
            return null;
        }

        if (isset($this->objectBoundAnonymousFunctions[spl_object_id($node)]) || $this->usesThis($node)) {
            return null;
        }

        $node->static = true;

        return $node;
    }

    /**
     * Whether the body reads `$this`, including through nested anonymous
     * functions.
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
