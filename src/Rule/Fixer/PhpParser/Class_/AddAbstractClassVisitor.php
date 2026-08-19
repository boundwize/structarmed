<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitorAbstract;

final class AddAbstractClassVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $className,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof Class_) {
            return null;
        }

        if ($node->isAbstract() || $node->isFinal() || $node->isAnonymous()) {
            return null;
        }

        if ($node->namespacedName?->toString() !== $this->className) {
            return null;
        }

        $node->flags |= Modifiers::ABSTRACT;

        return $node;
    }
}
