<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

/**
 * A string-backed enum case must not be backed by an empty string: `''`
 * carries no meaning when stored or serialised, and is easily confused with a
 * missing value. Cases whose value the analyser cannot resolve statically are
 * not checked.
 *
 * No fixer: changing a backing value may break APIs or persisted data.
 */
final readonly class EnumCaseValueMustNotBeEmptyRule implements MultipleRuleViolationInterface
{
    public function __construct(
        private string $layer
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer)
            && $classNode->enumBackingType === 'string';
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

        foreach ($classNode->enumCases as $enumCase) {
            if (! $enumCase->hasResolvedValue() || $enumCase->value !== '') {
                continue;
            }

            $violations[] = new RuleViolation(
                message:      sprintf(
                    'Enum case [%s::%s] must not have an empty backing value',
                    $classNode->className,
                    $enumCase->name
                ),
                file:         $classNode->file,
                line:         $enumCase->line !== 0 ? $enumCase->line : $classNode->line,
                className:    $classNode->className,
                layer:        $classNode->layer,
                constantName: $enumCase->name,
            );
        }

        return $violations;
    }
}
