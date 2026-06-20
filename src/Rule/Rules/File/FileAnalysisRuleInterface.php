<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\MultipleProjectRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

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
