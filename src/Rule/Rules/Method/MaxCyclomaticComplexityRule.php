<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MaxCyclomaticComplexityRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private int $maxComplexity,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->layer === $this->layer;
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        foreach ($classNode->methods as $method) {
            if ($method->cyclomaticComplexity > $this->maxComplexity) {
                return new RuleViolation(
                    ruleKey:   '',
                    message:   sprintf(
                        'Method [%s::%s()] has cyclomatic complexity of %d, maximum allowed is %d',
                        $classNode->className,
                        $method->name,
                        $method->cyclomaticComplexity,
                        $this->maxComplexity
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
