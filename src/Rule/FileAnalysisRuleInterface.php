<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;

interface FileAnalysisRuleInterface extends MultipleProjectRuleViolationInterface
{
    /**
     * @param list<string> $skipPaths
     * @return RuleViolation[]
     */
    public function evaluateProjectAllWithProvider(
        string $basePath,
        Architecture $architecture,
        FileAnalysisProvider $fileAnalysisProvider,
        array $skipPaths = [],
    ): array;
}
