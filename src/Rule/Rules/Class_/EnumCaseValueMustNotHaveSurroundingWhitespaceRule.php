<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function is_string;
use function sprintf;
use function trim;

/**
 * A string-backed enum case must not have leading or trailing whitespace in
 * its backing value (`' pending'`, `"active\t"`, `' '`): such values are easy
 * to mistype when matched with `from()` and invisible in most output.
 * Whitespace is what native `trim()` strips by default; whitespace inside the
 * value (`'in progress'`) is accepted. An empty string is left to
 * EnumCaseValueMustNotBeEmptyRule, and cases whose value the analyser cannot
 * resolve statically are not checked.
 *
 * No fixer: changing a backing value may break APIs or persisted data.
 */
final readonly class EnumCaseValueMustNotHaveSurroundingWhitespaceRule implements MultipleRuleViolationInterface
{
    public function __construct(
        private string $layer
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer)
            && $classNode->isBackedEnum()
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
            if (
                ! $enumCase->hasResolvedValue()
                || ! is_string($enumCase->value)
                || $enumCase->value === ''
                || $enumCase->value === trim($enumCase->value)
            ) {
                continue;
            }

            $violations[] = new RuleViolation(
                message:      sprintf(
                    'Enum case [%s::%s] must not have surrounding whitespace in its backing value',
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
