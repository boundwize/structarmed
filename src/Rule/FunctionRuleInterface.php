<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\FunctionNode;

/**
 * A rule evaluated against every named function declaration in the scanned
 * paths. The method names differ from {@see RuleInterface} so one rule class
 * can implement both and check classes and functions alike.
 */
interface FunctionRuleInterface
{
    /**
     * Whether this rule applies to the given FunctionNode at all.
     * Allows rules to skip nodes outside their scope.
     */
    public function appliesToFunction(FunctionNode $functionNode): bool;

    /**
     * Evaluate this rule against a FunctionNode.
     * Returns a RuleViolation if the rule is violated, null if it passes.
     */
    public function evaluateFunction(FunctionNode $functionNode): ?RuleViolation;
}
