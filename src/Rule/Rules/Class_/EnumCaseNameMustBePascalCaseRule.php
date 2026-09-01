<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function preg_match;
use function sprintf;

/**
 * PER Coding Style: enum cases MUST use PascalCase.
 *
 * @see https://www.php-fig.org/per/coding-style/#9-enumerations
 */
final readonly class EnumCaseNameMustBePascalCaseRule implements MultipleRuleViolationInterface
{
    public function __construct(
        private string $layer
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer)
            && $classNode->isEnum;
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
            if ((bool) preg_match('/^[A-Z][A-Za-z0-9]*$/', $enumCase->name)) {
                continue;
            }

            $violations[] = new RuleViolation(
                message:   sprintf(
                    'Enum case [%s::%s] must be declared in PascalCase',
                    $classNode->className,
                    $enumCase->name
                ),
                file:      $classNode->file,
                line:      $enumCase->line !== 0 ? $enumCase->line : $classNode->line,
                className: $classNode->className,
                layer:     $classNode->layer,
            );
        }

        return $violations;
    }
}
