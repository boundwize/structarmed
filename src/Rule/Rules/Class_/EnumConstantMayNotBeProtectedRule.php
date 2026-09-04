<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassConst\ChangeProtectedConstantToPrivateVisitor;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface as MultipleViolations;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

/**
 * PER Coding Style: enums cannot be extended, so enum constants MUST NOT be
 * declared protected; use private instead.
 *
 * @see https://www.php-fig.org/per/coding-style/#9-enumerations
 */
final readonly class EnumConstantMayNotBeProtectedRule extends AbstractPhpParserFixableRule implements
    MultipleViolations
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

        foreach ($classNode->constants as $constant) {
            if ($constant->visibility !== 'protected') {
                continue;
            }

            $violations[] = new RuleViolation(
                message:   sprintf(
                    'Enum constant [%s::%s] may not be declared protected, use private instead',
                    $classNode->className,
                    $constant->name
                ),
                file:      $classNode->file,
                line:      $constant->line !== 0 ? $constant->line : $classNode->line,
                className: $classNode->className,
                layer:     $classNode->layer,
                constantName: $constant->name,
            );
        }

        return $violations;
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): ChangeProtectedConstantToPrivateVisitor
    {
        /** @var string $constantName */
        $constantName = $ruleViolation->constantName;

        return new ChangeProtectedConstantToPrivateVisitor(
            $ruleViolation->className,
            $constantName
        );
    }
}
