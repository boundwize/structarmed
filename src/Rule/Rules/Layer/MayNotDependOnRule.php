<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Layer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;

final readonly class MayNotDependOnRule implements RuleInterface
{
    public function __construct(
        private string $from,
        private string $to,
        private string $toPath,
    ) {
    }

    public function appliesTo(ClassNode $classNode): bool
    {
        return $classNode->isInLayer($this->from);
    }

    public function evaluate(ClassNode $classNode): ?RuleViolation
    {
        foreach ($classNode->dependencies as $dependency) {
            $depPath = str_replace('\\', '/', $dependency);
            $toPath  = str_replace('\\', '/', $this->toPath);

            if (
                str_contains($depPath . '/', '/' . $toPath . '/')
                || str_starts_with($depPath . '/', $toPath . '/')
            ) {
                return new RuleViolation(
                    ruleKey:   '',
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

        return null;
    }
}
