<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

final class MaxCyclomaticComplexityRule implements RuleInterface
{
    public function __construct(
        private readonly string $layer,
        private readonly int $maxComplexity,
    ) {}

    public function appliesTo(ClassNode $node): bool
    {
        return $node->layer === $this->layer;
    }

    public function evaluate(ClassNode $node): ?RuleViolation
    {
        foreach ($node->methods as $method) {
            if ($method->cyclomaticComplexity > $this->maxComplexity) {
                return new RuleViolation(
                    ruleKey:   '',
                    message:   sprintf(
                        'Method [%s::%s()] has cyclomatic complexity of %d, maximum allowed is %d',
                        $node->className,
                        $method->name,
                        $method->cyclomaticComplexity,
                        $this->maxComplexity
                    ),
                    file:      $node->file,
                    line:      $node->line,
                    className: $node->className,
                    layer:     $node->layer,
                );
            }
        }

        return null;
    }
}
