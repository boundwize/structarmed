<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final readonly class ExtractionResult
{
    /**
     * @param list<ClassNode>             $classNodes
     * @param array<string, FileAnalysis> $fileAnalyses
     * @param list<AnonymousClassNode>    $anonymousClassNodes
     * @param array<string, list<string>> $fileReferences Class-like references made outside any
     *                                                    named class-like scope, per file
     * @param array<string, list<string>> $fileInstantiations Class-like instantiations (`new X`,
     *                                                        with self/static/parent resolved), per file
     */
    public function __construct(
        public array $classNodes,
        public array $fileAnalyses,
        public array $anonymousClassNodes = [],
        public array $fileReferences = [],
        public array $fileInstantiations = [],
    ) {
    }
}
