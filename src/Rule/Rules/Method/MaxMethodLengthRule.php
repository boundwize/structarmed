<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

final class MaxMethodLengthRule implements RuleInterface
{
    public function __construct(
        private readonly string $layer,
        private readonly int $maxLines,
    ) {}

    public function appliesTo(ClassNode $node): bool
    {
        return $node->layer === $this->layer;
    }

    public function evaluate(ClassNode $node): ?RuleViolation
    {
        foreach ($node->methods as $method) {
            if ($method->lineCount > $this->maxLines) {
                return new RuleViolation(
                    ruleKey:   '',
                    message:   sprintf(
                        'Method [%s::%s()] is %d lines long, maximum allowed is %d',
                        $node->className,
                        $method->name,
                        $method->lineCount,
                        $this->maxLines
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
