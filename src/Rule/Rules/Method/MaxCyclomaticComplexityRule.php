<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Method;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MaxCyclomaticComplexityRule implements MultipleRuleViolationInterface
{
    public function __construct(
        private string $layer,
        private int $maxComplexity,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        return $this->evaluateAll($classNode)[0] ?? null;
    }

    /**
     * @return list<RuleViolation>
     */
    public function evaluateAll(ClassNode $classNode): array
    {
        $violations = [];

        foreach ($classNode->methods as $method) {
            if ($method->cyclomaticComplexity > $this->maxComplexity) {
                $violations[] = new RuleViolation(
                    message:   sprintf(
                        'Method [%s::%s()] has cyclomatic complexity of %d, maximum allowed is %d',
                        $classNode->className,
                        $method->name,
                        $method->cyclomaticComplexity,
                        $this->maxComplexity
                    ),
                    file:      $classNode->file,
                    line:      $method->line !== 0 ? $method->line : $classNode->line,
                    className: $classNode->className,
                    layer:     $classNode->layer,
                );
            }
        }

        return $violations;
    }
}
