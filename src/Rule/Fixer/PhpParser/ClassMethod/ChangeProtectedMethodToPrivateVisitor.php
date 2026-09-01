<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassMethod;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\NodeVisitorAbstract;

final class ChangeProtectedMethodToPrivateVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $className,
        private readonly string $methodName,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof Enum_) {
            return null;
        }

        if ($node->namespacedName?->toString() !== $this->className) {
            return null;
        }

        $classMethod = $node->getMethod($this->methodName);
        if (! $classMethod instanceof ClassMethod) {
            return null;
        }

        if (($classMethod->flags & Modifiers::PROTECTED) === 0) {
            return null;
        }

        $classMethod->flags = ($classMethod->flags & ~Modifiers::PROTECTED) | Modifiers::PRIVATE;

        return $node;
    }
}
