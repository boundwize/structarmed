<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassLike;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

final class RemoveClassLikeVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $className,
    ) {
    }

    public function leaveNode(Node $node): ?int
    {
        if (! $node instanceof ClassLike) {
            return null;
        }

        // Anonymous classes have no namespacedName at all.
        if (! isset($node->namespacedName)) {
            return null;
        }

        if ($node->namespacedName->toString() !== $this->className) {
            return null;
        }

        return NodeVisitor::REMOVE_NODE;
    }
}
