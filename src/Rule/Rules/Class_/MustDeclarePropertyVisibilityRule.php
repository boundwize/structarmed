<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MustDeclarePropertyVisibilityRule implements MultipleRuleViolationInterface
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

        foreach ($classNode->properties as $property) {
            if ($property->hasExplicitVisibility) {
                continue;
            }

            $violations[] = new RuleViolation(
                message:   sprintf(
                    'Property [%s::$%s] must declare an explicit visibility (public, protected, or private)',
                    $classNode->className,
                    $property->name
                ),
                file:      $classNode->file,
                line:      $property->line !== 0 ? $property->line : $classNode->line,
                className: $classNode->className,
                layer:     $classNode->layer,
            );
        }

        return $violations;
    }
}
