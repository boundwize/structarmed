<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function preg_match;
use function sprintf;

final readonly class ClassConstantNameMustBeUpperCaseRule implements MultipleRuleViolationInterface
{
    public function __construct(
        private string $layer
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

        foreach ($classNode->constants as $constant) {
            if ((bool) preg_match('/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/', $constant->name)) {
                continue;
            }

            $violations[] = new RuleViolation(
                message:   sprintf(
                    'Class constant [%s::%s] must be declared in upper case with underscore separators',
                    $classNode->className,
                    $constant->name
                ),
                file:      $classNode->file,
                line:      $constant->line !== 0 ? $constant->line : $classNode->line,
                className: $classNode->className,
                layer:     $classNode->layer,
            );
        }

        return $violations;
    }
}
