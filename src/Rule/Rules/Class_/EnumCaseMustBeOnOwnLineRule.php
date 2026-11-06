<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\EnumCase\MoveEnumCaseToOwnLineVisitor;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface as MultipleViolations;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

/**
 * PER Coding Style: each enum case MUST be declared on its own line, sharing
 * it with no other case, member, or brace of the enum.
 *
 * @see https://www.php-fig.org/per/coding-style/#9-enumerations
 */
final readonly class EnumCaseMustBeOnOwnLineRule extends AbstractPhpParserFixableRule implements MultipleViolations
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
            if (! $enumCase->sharesLine) {
                continue;
            }

            // The case name, not the line, identifies the case to the fixer:
            // another rule's fix earlier in the same pass may shift lines.
            $violations[] = new RuleViolation(
                message:      sprintf(
                    'Enum case [%s::%s] must be declared on its own line',
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

    protected function createFixerVisitor(RuleViolation $ruleViolation): MoveEnumCaseToOwnLineVisitor
    {
        /** @var string $caseName */
        $caseName = $ruleViolation->constantName;

        return new MoveEnumCaseToOwnLineVisitor($ruleViolation->className, $caseName);
    }
}
