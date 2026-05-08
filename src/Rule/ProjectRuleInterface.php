<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Architecture;

interface ProjectRuleInterface
{
    /**
     * Evaluate this rule against the project as a whole.
     */
    public function evaluateProject(string $basePath, Architecture $architecture): ?RuleViolation;
}
