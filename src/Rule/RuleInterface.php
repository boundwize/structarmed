<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\ClassNode;

interface RuleInterface
{
    /**
     * Evaluate this rule against a ClassNode.
     * Returns a RuleViolation if the rule is violated, null if it passes.
     */
    public function evaluate(ClassNode $classNode): ?RuleViolation;

    /**
     * Whether this rule applies to the given ClassNode at all.
     * Allows rules to skip nodes outside their scope.
     */
    public function appliesTo(ClassNode $classNode): bool;
}
