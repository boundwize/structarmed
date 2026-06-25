<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\Method;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeVisitorAbstract;

final class AddPublicMethodVisibilityVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $className,
        private readonly string $methodName,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof ClassLike) {
            return null;
        }

        if (! isset($node->namespacedName) || $this->className !== $node->namespacedName->toString()) {
            return null;
        }

        $classMethod = $node->getMethod($this->methodName);
        if (! $classMethod instanceof ClassMethod) {
            return null;
        }

        if (($classMethod->flags & Modifiers::VISIBILITY_MASK) !== 0) {
            return null;
        }

        $classMethod->flags |= Modifiers::PUBLIC;

        return $node;
    }
}
