<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\FunctionLike;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\NodeVisitorAbstract;

/**
 * Adds the `static` modifier to the closure or arrow function that starts on
 * the given line. An anonymous function has no name, so its start line is
 * the only stable identity a violation can carry.
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

        $node->static = true;

        return $node;
    }
}
