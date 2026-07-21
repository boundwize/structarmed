<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final readonly class ExtractionResult
{
    /**
     * @param list<ClassNode>             $classNodes
     * @param array<string, FileAnalysis> $fileAnalyses
     * @param list<AnonymousClassNode>    $anonymousClassNodes
     */
    public function __construct(
        public array $classNodes,
        public array $fileAnalyses,
        public array $anonymousClassNodes = [],
    ) {
    }
}
