<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

/**
 * @internal
 */
final readonly class AnalysisNodeExtractor
{
    private FileAnalysisProvider $fileAnalysisProvider;

    /**
     * @param AnalysisResultCache|null $analysisResultCache When given, every extracted file's nodes
     *                                                      are stored under $analysisNodeCacheNamespace.
     */
    public function __construct(
        private LayerResolverInterface $layerResolver,
        ?FileAnalysisProvider $fileAnalysisProvider = null,
        private ?AnalysisResultCache $analysisResultCache = null,
        private string $analysisNodeCacheNamespace = '',
    ) {
        $this->fileAnalysisProvider = $fileAnalysisProvider ?? new FileAnalysisProvider();
    }

    /** @param list<string> $files */
    public function extract(
        array $files,
        ?ProgressHandlerInterface $progressHandler = null,
        bool $withFileAnalysis = true,
    ): ExtractionResult {
        $analysisNodeCollector = new AnalysisNodeCollector($this->layerResolver);
        $nodeTraverser         = new NodeTraverser(new NameResolver(), $analysisNodeCollector);
        $fileAnalyses          = [];

        foreach ($files as $file) {
            try {
                $ast = $this->fileAnalysisProvider->ast($file, $withFileAnalysis);

                if ($withFileAnalysis) {
                    $fileAnalyses[$file] = $this->fileAnalysisProvider->analyse($file);
                }

                if ($ast === null || $ast === []) {
                    continue;
                }

                $analysisNodeCollector->setCurrentFile($file);
                $nodeTraverser->traverse($ast);
            } finally {
                if ($withFileAnalysis) {
                    $this->fileAnalysisProvider->releaseAst($file);
                }

                $progressHandler?->advance($file);
            }
        }

        $extractionResult = new ExtractionResult(
            $analysisNodeCollector->getNodes(),
            $fileAnalyses,
            $analysisNodeCollector->getAnonymousClassNodes(),
            $analysisNodeCollector->getFileReferences(),
            $analysisNodeCollector->getFileInstantiations(),
            $analysisNodeCollector->getFunctionNodes(),
            $analysisNodeCollector->getAnonymousFunctionNodes(),
        );

        $this->analysisResultCache?->storeExtractionResult($files, $this->analysisNodeCacheNamespace, $extractionResult);

        return $extractionResult;
    }
}
