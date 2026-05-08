<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

final class NamingConventionRule implements RuleInterface
{
    public function __construct(
        private readonly string $classNamePattern,
        private readonly string $mustBeInLayer,
        private readonly bool $excludeInterfaces = false,
        private readonly ?string $excludePattern = null,
    ) {}

    public function appliesTo(ClassNode $node): bool
    {
        if ($this->excludeInterfaces && $node->isInterface) {
            return false;
        }

        if (
            $this->excludePattern !== null
            && (bool) preg_match($this->excludePattern, $node->shortName())
        ) {
            return false;
        }

        return (bool) preg_match($this->classNamePattern, $node->shortName());
    }

    public function evaluate(ClassNode $node): ?RuleViolation
    {
        if ($node->layer === $this->mustBeInLayer) {
            return null;
        }

        return new RuleViolation(
            ruleKey:   '',
            message:   sprintf(
                'Class [%s] matching pattern [%s] must live in layer [%s], found in layer [%s]',
                $node->className,
                $this->classNamePattern,
                $this->mustBeInLayer,
                $node->layer ?? 'unknown'
            ),
            file:      $node->file,
            line:      $node->line,
            className: $node->className,
            layer:     $node->layer,
        );
    }
}
