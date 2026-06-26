<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

interface FixableInterface
{
    /**
     * Apply a fix for the given violation.
     *
     * Returns true when the underlying source file was changed.
     */
    public function fix(RuleViolation $ruleViolation): bool;
}
