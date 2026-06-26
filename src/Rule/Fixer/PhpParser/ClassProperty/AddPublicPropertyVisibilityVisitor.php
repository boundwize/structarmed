<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassProperty;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;

final class AddPublicPropertyVisibilityVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $className,
        private readonly string $propertyName,
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

        $property = $node->getProperty($this->propertyName);
        if (! $property instanceof Property) {
            return null;
        }

        if (($property->flags & Modifiers::VISIBILITY_MASK) !== 0) {
            return null;
        }

        $property->flags |= Modifiers::PUBLIC;

        return $node;
    }
}
