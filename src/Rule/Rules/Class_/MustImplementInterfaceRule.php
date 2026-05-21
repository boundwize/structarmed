<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MustImplementInterfaceRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private string $interface,
        private ?string $classNamePattern = null,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        if (! $classNode->isClass() || ! $classNode->isInLayer($this->layer)) {
            return false;
        }

        if ($this->classNamePattern !== null) {
            return $classNode->nameMatches($this->classNamePattern, isFullName: true);
        }

        return true;
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if ($classNode->implementsInterface($this->interface)) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Class [%s] must implement interface [%s]',
                $classNode->className,
                $this->interface
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
