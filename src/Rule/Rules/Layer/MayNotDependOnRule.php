<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\Layer;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

final class MayNotDependOnRule implements RuleInterface
{
    public function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly string $toPath,
    ) {}

    public function appliesTo(ClassNode $node): bool
    {
        return $node->layer === $this->from;
    }

    public function evaluate(ClassNode $node): ?RuleViolation
    {
        foreach ($node->dependencies as $dependency) {
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
                        $node->className,
                        $this->from,
                        $dependency,
                        $this->to
                    ),
                    file:      $node->file,
                    line:      $node->line,
                    className: $node->className,
                    layer:     $node->layer,
                );
            }
        }

        return null;
    }
}
