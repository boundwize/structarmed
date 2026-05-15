<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function preg_match;
use function sprintf;

final readonly class NamingConventionRule implements RuleInterface
{
    public function __construct(
        private string $classNamePattern,
        private string $mustBeInLayer,
        private bool $excludeInterfaces = false,
        private ?string $excludePattern = null,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        if ($this->excludeInterfaces && $classNode->isInterface) {
            return false;
        }

        if (
            $this->excludePattern !== null
            && (bool) preg_match($this->excludePattern, $classNode->className)
        ) {
            return false;
        }

        return (bool) preg_match($this->classNamePattern, $classNode->className);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        if ($classNode->isInLayer($this->mustBeInLayer)) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Class [%s] matching pattern [%s] must live in layer [%s], found in layer [%s]',
                $classNode->className,
                $this->classNamePattern,
                $this->mustBeInLayer,
                $classNode->layer ?? 'unknown'
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
