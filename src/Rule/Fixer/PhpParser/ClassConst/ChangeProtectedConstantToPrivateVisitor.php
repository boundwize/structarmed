<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassConst;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\NodeVisitorAbstract;

final class ChangeProtectedConstantToPrivateVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $className,
        private readonly string $constantName,
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

        foreach ($node->getConstants() as $classConstant) {
            if (! $this->containsConstant($classConstant)) {
                continue;
            }

            if (($classConstant->flags & Modifiers::PROTECTED) === 0) {
                return null;
            }

            $classConstant->flags = ($classConstant->flags & ~Modifiers::PROTECTED) | Modifiers::PRIVATE;

            return $node;
        }

        return null;
    }

    private function containsConstant(ClassConst $classConst): bool
    {
        foreach ($classConst->consts as $constant) {
            if ($constant->name->toString() === $this->constantName) {
                return true;
            }
        }

        return false;
    }
}
