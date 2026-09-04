<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\FunctionNode;

/**
 * A rule evaluated against every named function declaration in the scanned
 * paths. The method names mirror {@see RuleInterface}; a rule class
 * implementing more than one rule interface must widen the parameter to a
 * union type and branch on the node type.
 */
interface FunctionRuleInterface
{
    /**
     * Whether this rule applies to the given FunctionNode at all.
     * Allows rules to skip nodes outside their scope.
     */
    public function appliesTo(FunctionNode $functionNode): bool;

    /**
     * Evaluate this rule against a FunctionNode.
     * Returns a RuleViolation if the rule is violated, null if it passes.
     */
    public function evaluate(FunctionNode $functionNode): ?RuleViolation;
}
