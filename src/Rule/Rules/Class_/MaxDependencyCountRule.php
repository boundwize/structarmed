<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Class_;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class MaxDependencyCountRule implements RuleInterface
{
    public function __construct(
        private string $layer,
        private int $maxCount,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->layer) && ! $classNode->isInterface;
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        $count = $classNode->constructorParamCount();

        if ($count <= $this->maxCount) {
            return null;
        }

        return new RuleViolation(
            message:   sprintf(
                'Class [%s] has %d constructor dependencies, maximum allowed is %d',
                $classNode->className,
                $count,
                $this->maxCount
            ),
            file:      $classNode->file,
            line:      $classNode->line,
            className: $classNode->className,
            layer:     $classNode->layer,
        );
    }
}
