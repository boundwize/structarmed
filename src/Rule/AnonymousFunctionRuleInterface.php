<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;

/**
 * A rule evaluated against every closure and arrow function in the scanned
 * paths, wherever it is declared. The method names mirror {@see RuleInterface};
 * a rule class implementing more than one rule interface must widen the
 * parameter to a union type and branch on the node type.
 */
interface AnonymousFunctionRuleInterface
{
    /**
     * Whether this rule applies to the given AnonymousFunctionNode at all.
     * Allows rules to skip nodes outside their scope.
     */
    public function appliesTo(AnonymousFunctionNode $anonymousFunctionNode): bool;

    /**
     * Evaluate this rule against an AnonymousFunctionNode.
     * Returns a RuleViolation if the rule is violated, null if it passes.
     */
    public function evaluate(AnonymousFunctionNode $anonymousFunctionNode): ?RuleViolation;
}
