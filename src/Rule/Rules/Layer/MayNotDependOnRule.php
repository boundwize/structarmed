<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Layer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;

final readonly class MayNotDependOnRule implements MultipleRuleViolationInterface
{
    private string $normalisedToPath;

    public function __construct(
        private string $from,
        private string $to,
        string $toPath,
    ) {
        $this->normalisedToPath = str_replace('\\', '/', $toPath);
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->from);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        $violations = $this->evaluateAll($classNode);

        return $violations[0] ?? null;
    }

    /**
     * @return RuleViolation[]
     */
    public function evaluateAll(ClassNode $classNode): array
    {
        $violations = [];

        foreach ($classNode->dependencies as $dependency) {
            $depPath = str_replace('\\', '/', $dependency);

            if (
                str_contains($depPath . '/', '/' . $this->normalisedToPath . '/')
                || str_starts_with($depPath . '/', $this->normalisedToPath . '/')
            ) {
                $violations[] = new RuleViolation(
                    message:   sprintf(
                        'Class [%s] in layer [%s] must not depend on [%s] which belongs to layer [%s]',
                        $classNode->className,
                        $this->from,
                        $dependency,
                        $this->to
                    ),
                    file:      $classNode->file,
                    line:      $classNode->line,
                    className: $classNode->className,
                    layer:     $classNode->layer,
                );
            }
        }

        return $violations;
    }
}
