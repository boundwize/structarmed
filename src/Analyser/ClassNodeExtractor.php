<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

final readonly class ClassNodeExtractor
{
    private FileAnalysisProvider $fileAnalysisProvider;

    public function __construct(
        private LayerResolverInterface $layerResolver,
        ?FileAnalysisProvider $fileAnalysisProvider = null,
    ) {
        $this->fileAnalysisProvider = $fileAnalysisProvider ?? new FileAnalysisProvider();
    }

    /** @param list<string> $files */
    public function extract(
        array $files,
        ?ProgressHandlerInterface $progressHandler = null,
        bool $withFileAnalysis = true,
    ): ExtractionResult {
        $classCollector = new ClassCollector($this->layerResolver);
        $nodeTraverser  = new NodeTraverser(new NameResolver(), $classCollector);
        $fileAnalyses   = [];

        foreach ($files as $file) {
            try {
                $ast = $this->fileAnalysisProvider->ast($file, $withFileAnalysis);

                if ($withFileAnalysis) {
                    $fileAnalyses[$file] = $this->fileAnalysisProvider->analyse($file);
                }

                if ($ast === null || $ast === []) {
                    continue;
                }

                $classCollector->setCurrentFile($file);
                $nodeTraverser->traverse($ast);
            } finally {
                if ($withFileAnalysis) {
                    $this->fileAnalysisProvider->releaseAst($file);
                }

                $progressHandler?->advance($file);
            }
        }

        return new ExtractionResult($classCollector->getNodes(), $fileAnalyses);
    }
}
