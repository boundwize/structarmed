<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final readonly class ExtractionResult
{
    /**
     * @param list<ClassNode>             $classNodes
     * @param array<string, FileAnalysis> $fileAnalyses
     */
    public function __construct(
        public array $classNodes,
        public array $fileAnalyses,
    ) {
    }
}
