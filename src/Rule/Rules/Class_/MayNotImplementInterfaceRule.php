<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MayNotImplementInterfaceRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private string $interface,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if (! $classNode->implementsInterface($this->interface)) {
            return null;
        }

        // Interfaces extend other interfaces; classes and enums implement them.
        $relation = $classNode->isInterface ? 'extend' : 'implement';

        return new RuleViolation(
            message:   sprintf(
                '%s [%s] must not %s interface [%s]',
                $classNode->getType(),
                $classNode->className,
                $relation,
                $this->interface
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
