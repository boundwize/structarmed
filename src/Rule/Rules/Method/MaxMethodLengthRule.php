<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MaxMethodLengthRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private int $maxLines,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->layer === $this->layer;
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        foreach ($classNode->methods as $method) {
            if ($method->lineCount > $this->maxLines) {
                return new RuleViolation(
                    ruleKey:   '',
                    message:   sprintf(
                        'Method [%s::%s()] is %d lines long, maximum allowed is %d',
                        $classNode->className,
                        $method->name,
                        $method->lineCount,
                        $this->maxLines
                    ),
                    file:      $classNode->file,
                    line:      $classNode->line,
                    className: $classNode->className,
                    layer:     $classNode->layer,
                );
            }
        }

        return null;
    }
}
