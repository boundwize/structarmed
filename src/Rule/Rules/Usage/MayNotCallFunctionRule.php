<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

final class MayNotCallFunctionRule implements RuleInterface
{
    public function __construct(
        private readonly string $layer,
        private readonly string $function,
    ) {}

    public function appliesTo(ClassNode $node): bool
    {
        return $node->layer === $this->layer;
    }

    public function evaluate(ClassNode $node): ?RuleViolation
    {
        if (! $node->callsFunction($this->function)) {
            return null;
        }

        return new RuleViolation(
            ruleKey:   '',
            message:   sprintf(
                'Class [%s] must not call function [%s()]',
                $node->className,
                $this->function
            ),
            file:      $node->file,
            line:      $node->line,
            className: $node->className,
            layer:     $node->layer,
        );
    }
}
