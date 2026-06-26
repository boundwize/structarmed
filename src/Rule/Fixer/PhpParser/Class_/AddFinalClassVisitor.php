<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\Class_;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitorAbstract;

final class AddFinalClassVisitor extends NodeVisitorAbstract
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

        if ($node->isFinal() || $node->isAbstract()) {
            return null;
        }

        if ($node->namespacedName?->toString() !== $this->className) {
            return null;
        }

        $node->flags |= Modifiers::FINAL;

        return $node;
    }
}
