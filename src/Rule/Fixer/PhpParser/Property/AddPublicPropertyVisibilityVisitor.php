<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Fixer\PhpParser\Property;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;

use function is_string;

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
        if ($property instanceof Property) {
            if (($property->flags & Modifiers::VISIBILITY_MASK) !== 0) {
                return null;
            }

            $property->flags |= Modifiers::PUBLIC;

            return $node;
        }

        return $this->fixPromotedProperty($node);
    }

    private function fixPromotedProperty(ClassLike $classLike): ?ClassLike
    {
        $constructor = $classLike->getMethod('__construct');
        if (! $constructor instanceof ClassMethod) {
            return null;
        }

        foreach ($constructor->params as $param) {
            if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                continue;
            }

            if ($param->var->name !== $this->propertyName) {
                continue;
            }

            // param names are unique, so the first name match decides:
            // only a param already promoted (eg: readonly) may gain a visibility
            if (! $param->isPromoted() || ($param->flags & Modifiers::VISIBILITY_MASK) !== 0) {
                return null;
            }

            $param->flags |= Modifiers::PUBLIC;

            return $classLike;
        }

        return null;
    }
}
