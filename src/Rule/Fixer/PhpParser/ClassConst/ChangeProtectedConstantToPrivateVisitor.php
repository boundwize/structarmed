<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassConst;

use Boundwize\StructArmed\Util\PhpParser\ProtectedToPrivateFlags;
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

            $flags = ProtectedToPrivateFlags::tryFrom($classConstant->flags);
            if ($flags === null) {
                return null;
            }

            $classConstant->flags = $flags;

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
