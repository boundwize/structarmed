<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\AnonymousClassNode;

/**
 * A rule evaluated against every anonymous class (`new class ... {}`) in the
 * scanned paths, wherever it is declared. The method names mirror
 * {@see RuleInterface}; a rule class implementing more than one rule
 * interface must widen the parameter to a union type and branch on the node
 * type.
 */
interface AnonymousClassRuleInterface
{
    /**
     * Whether this rule applies to the given AnonymousClassNode at all.
     * Allows rules to skip nodes outside their scope.
     */
    public function appliesTo(AnonymousClassNode $anonymousClassNode): bool;

    /**
     * Evaluate this rule against an AnonymousClassNode.
     * Returns a RuleViolation if the rule is violated, null if it passes.
     */
    public function evaluate(AnonymousClassNode $anonymousClassNode): ?RuleViolation;
}
