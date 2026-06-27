<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule as PhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ClassConst\AddPublicConstantVisibilityVisitor;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface as MultipleViolations;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MustDeclareConstantVisibilityRule extends PhpParserFixableRule implements MultipleViolations
{
    public function __construct(
        private string $layer,
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
            if ($constant->hasExplicitVisibility) {
                continue;
            }

            $violations[] = new RuleViolation(
                message:   sprintf(
                    'Constant [%s::%s] must declare an explicit visibility (public, protected, or private)',
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

    protected function createFixerVisitor(RuleViolation $ruleViolation): AddPublicConstantVisibilityVisitor
    {
        /** @var string $constantName */
        $constantName = $ruleViolation->constantName;

        return new AddPublicConstantVisibilityVisitor(
            $ruleViolation->className,
            $constantName
        );
    }
}
