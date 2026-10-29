<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\AbstractLayerAwareRule;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final class ApiMayNotExtendInternalRule extends AbstractLayerAwareRule implements RuleInterface
{
    public function __construct(
        private readonly string $layer,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        // Traits and enums cannot extend
        if (! $classNode->isClass() && ! $classNode->isInterface) {
            return false;
        }

        return $classNode->isApi && $classNode->isInLayer($this->layer);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        // Direct and transitive parents; a class's parentInterfaces would add
        // the interfaces it implements, which it does not extend
        $parents = $classNode->isInterface ? $classNode->parentInterfaces : $classNode->parentClasses;

        foreach ($parents as $parent) {
            // Only a scanned parent has a known docblock
            $parentNode = $this->getDependencyNode($parent);

            if (! $parentNode instanceof ClassNode || ! $parentNode->isInternal) {
                continue;
            }

            return new RuleViolation(
                message:   sprintf(
                    '@api %s [%s] must not extend @internal %s [%s]',
                    $classNode->getType(),
                    $classNode->className,
                    $parentNode->getType(),
                    $parentNode->className
                ),
                file:      $classNode->file,
                line:      $classNode->line,
                className: $classNode->className,
                layer:     $classNode->layer,
            );
        }

        return null;
    }
}
