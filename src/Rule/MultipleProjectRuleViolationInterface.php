<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Architecture;

interface MultipleProjectRuleViolationInterface extends ProjectRuleInterface
{
    /**
     * @param list<string> $skipPaths
     * @return list<RuleViolation>
     */
    public function evaluateProjectAll(
        string $basePath,
        Architecture $architecture,
        array $skipPaths = []
    ): array;
}
