<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassMethod;

use Boundwize\StructArmed\Util\PhpParser\ProtectedToPrivateFlags;
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

        $flags = ProtectedToPrivateFlags::tryFrom($classMethod->flags);
        if ($flags === null) {
            return null;
        }

        $classMethod->flags = $flags;

        return $node;
    }
}
