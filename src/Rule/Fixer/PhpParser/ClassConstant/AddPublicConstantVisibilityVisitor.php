<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassConstant;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeVisitorAbstract;

final class AddPublicConstantVisibilityVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $className,
        private readonly string $constantName,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $node instanceof ClassLike) {
            return null;
        }

        if ($node->namespacedName?->toString() !== $this->className) {
            return null;
        }

        foreach ($node->getConstants() as $classConstant) {
            if (! $this->containsConstant($classConstant)) {
                continue;
            }

            if (($classConstant->flags & Modifiers::VISIBILITY_MASK) !== 0) {
                return null;
            }

            $classConstant->flags |= Modifiers::PUBLIC;

            return $node;
        }

        return null;
    }

    private function containsConstant(ClassConst $classConstant): bool
    {
        foreach ($classConstant->consts as $constant) {
            if ($constant->name->toString() === $this->constantName) {
                return true;
            }
        }

        return false;
    }
}
