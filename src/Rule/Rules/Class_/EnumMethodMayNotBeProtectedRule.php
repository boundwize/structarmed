<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassMethod\ChangeProtectedMethodToPrivateVisitor;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface as MultipleViolations;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

/**
 * PER Coding Style: enums cannot be extended, so enum methods MUST NOT be
 * declared protected; use private instead.
 *
 * @see https://www.php-fig.org/per/coding-style/#9-enumerations
 */
final readonly class EnumMethodMayNotBeProtectedRule extends AbstractPhpParserFixableRule implements MultipleViolations
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

        foreach ($classNode->methods as $method) {
            if ($method->visibility !== 'protected') {
                continue;
            }

            $violations[] = new RuleViolation(
                message:   sprintf(
                    'Enum method [%s::%s] may not be declared protected, use private instead',
                    $classNode->className,
                    $method->name
                ),
                file:      $classNode->file,
                line:      $method->line !== 0 ? $method->line : $classNode->line,
                className: $classNode->className,
                layer:     $classNode->layer,
                methodName: $method->name,
            );
        }

        return $violations;
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): ChangeProtectedMethodToPrivateVisitor
    {
        /** @var string $methodName */
        $methodName = $ruleViolation->methodName;

        return new ChangeProtectedMethodToPrivateVisitor(
            $ruleViolation->className,
            $methodName
        );
    }
}
