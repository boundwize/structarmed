<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

final class MustBeInterfaceRule implements RuleInterface
{
    public function __construct(
        private readonly string $layer,
        private readonly ?string $classNamePattern = null,
    ) {}

    public function appliesTo(ClassNode $node): bool
    {
        if ($node->layer !== $this->layer) {
            return false;
        }

        if ($this->classNamePattern !== null) {
            return (bool) preg_match($this->classNamePattern, $node->shortName());
        }

        return true;
    }

    public function evaluate(ClassNode $node): ?RuleViolation
    {
        if ($node->isInterface) {
            return null;
        }

        return new RuleViolation(
            ruleKey:   '',
            message:   sprintf(
                'Class [%s] must be an interface',
                $node->className
            ),
            file:      $node->file,
            line:      $node->line,
            className: $node->className,
            layer:     $node->layer,
        );
    }
}
