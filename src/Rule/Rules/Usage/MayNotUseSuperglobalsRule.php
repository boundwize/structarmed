<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Usage;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

final class MayNotUseSuperglobalsRule implements RuleInterface
{
    public function __construct(
        private readonly string $layer,
    ) {}

    public function appliesTo(ClassNode $node): bool
    {
        return $node->layer === $this->layer;
    }

    public function evaluate(ClassNode $node): ?RuleViolation
    {
        if (! $node->accessesSuperglobals()) {
            return null;
        }

        return new RuleViolation(
            ruleKey:   '',
            message:   sprintf(
                'Class [%s] must not access superglobals directly (%s)',
                $node->className,
                implode(', ', $node->superglobals)
            ),
            file:      $node->file,
            line:      $node->line,
            className: $node->className,
            layer:     $node->layer,
        );
    }
}
