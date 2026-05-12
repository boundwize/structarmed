<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Architecture;

interface ProjectRuleInterface
{
    /**
     * Evaluate this rule against the project as a whole.
     *
     * @param list<string> $skipPaths Paths to exclude from evaluation (absolute or relative to $basePath)
     */
    public function evaluateProject(
        string $basePath,
        Architecture $architecture,
        array $skipPaths = []
    ): ?RuleViolation;
}
