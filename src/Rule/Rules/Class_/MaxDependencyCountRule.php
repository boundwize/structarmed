<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

final class MaxDependencyCountRule implements RuleInterface
{
    public function __construct(
        private readonly string $layer,
        private readonly int $maxCount,
    ) {}

    public function appliesTo(ClassNode $node): bool
    {
        return $node->layer === $this->layer && ! $node->isInterface;
    }

    public function evaluate(ClassNode $node): ?RuleViolation
    {
        $count = $node->constructorParamCount();

        if ($count <= $this->maxCount) {
            return null;
        }

        return new RuleViolation(
            ruleKey:   '',
            message:   sprintf(
                'Class [%s] has %d constructor dependencies, maximum allowed is %d',
                $node->className,
                $count,
                $this->maxCount
            ),
            file:      $node->file,
            line:      $node->line,
            className: $node->className,
            layer:     $node->layer,
        );
    }
}
