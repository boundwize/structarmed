<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;

/**
 * A rule evaluated against every closure and arrow function in the scanned
 * paths, wherever it is declared. The method names differ from
 * {@see RuleInterface} so one rule class can implement both.
 */
interface AnonymousFunctionRuleInterface
{
    /**
     * Whether this rule applies to the given AnonymousFunctionNode at all.
     * Allows rules to skip nodes outside their scope.
     */
    public function appliesToAnonymousFunction(AnonymousFunctionNode $anonymousFunctionNode): bool;

    /**
     * Evaluate this rule against an AnonymousFunctionNode.
     * Returns a RuleViolation if the rule is violated, null if it passes.
     */
    public function evaluateAnonymousFunction(AnonymousFunctionNode $anonymousFunctionNode): ?RuleViolation;
}
