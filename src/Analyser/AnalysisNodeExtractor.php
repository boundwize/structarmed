<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

use function array_push;
use function count;

/**
 * @internal
 */
final readonly class AnalysisNodeExtractor
{
    private FileAnalysisProvider $fileAnalysisProvider;

    /**
     * @param LayerResolverInterface   $layerResolver       Resolves no layers by default, which is enough
     *                                                      for rules that only need the file facts and
     *                                                      run outside the analyser.
     * @param AnalysisResultCache|null $analysisResultCache When given, every extracted file's nodes
     *                                                      are stored under $analysisNodeCacheNamespace.
     */
    public function __construct(
        private LayerResolverInterface $layerResolver = new ChainLayerResolver(),
        ?FileAnalysisProvider $fileAnalysisProvider = null,
        private ?AnalysisResultCache $analysisResultCache = null,
        private string $analysisNodeCacheNamespace = '',
    ) {
        $this->fileAnalysisProvider = $fileAnalysisProvider ?? new FileAnalysisProvider();
    }

    /**
     * Only files without a valid node-cache payload are parsed, and only those
     * count towards the progress total.
     *
     * @param list<string> $files
     */
    public function extract(
        array $files,
        ?ProgressHandlerInterface $progressHandler = null,
        bool $withFileAnalysis = true,
    ): ExtractionResult {
        [$cachedResult, $filesToParse] = $this->loadFromCache($files, $withFileAnalysis);

        $progressHandler?->start(count($filesToParse));

        $analysisNodeCollector = new AnalysisNodeCollector($this->layerResolver);
        $nodeTraverser         = new NodeTraverser(new NameResolver(), $analysisNodeCollector);
        $fileAnalyses          = [];

        foreach ($filesToParse as $fileToParse) {
            try {
                $ast                          = $this->fileAnalysisProvider->ast($fileToParse, $withFileAnalysis);
                $nonCanonicalKeywordConstants = [];
                $numericLiterals              = [];

                if ($ast !== null && $ast !== []) {
                    $analysisNodeCollector->setCurrentFile($fileToParse, $this->fileAnalysisProvider->tokens());
                    $nodeTraverser->traverse($ast);

                    $nonCanonicalKeywordConstants = $analysisNodeCollector->getNonCanonicalKeywordConstants();
                    $numericLiterals              = $analysisNodeCollector->getNumericLiterals();
                }

                // Analysed after the traversal so the facts only the collector
                // records reach the file analysis without a second AST walk.
                if ($withFileAnalysis) {
                    $fileAnalyses[$fileToParse] = $this->fileAnalysisProvider->analyse(
                        $fileToParse,
                        $nonCanonicalKeywordConstants,
                        $numericLiterals,
                    );
                }
            } finally {
                if ($withFileAnalysis) {
                    $this->fileAnalysisProvider->releaseAst($fileToParse);
                }

                $progressHandler?->advance($fileToParse);
            }
        }

        $extractionResult = new ExtractionResult(
            $analysisNodeCollector->getClassNodes(),
            $fileAnalyses,
            $analysisNodeCollector->getAnonymousClassNodes(),
            $analysisNodeCollector->getFileReferences(),
            $analysisNodeCollector->getFileInstantiations(),
            $analysisNodeCollector->getFunctionNodes(),
            $analysisNodeCollector->getAnonymousFunctionNodes(),
        );

        $this->analysisResultCache?->storeExtractionResult(
            $filesToParse,
            $this->analysisNodeCacheNamespace,
            $extractionResult
        );

        return $cachedResult->merge($extractionResult);
    }

    /**
     * Hydrates every file with a valid node-cache payload; the rest still need parsing.
     *
     * @param list<string> $files
     * @return array{ExtractionResult, list<string>}
     */
    private function loadFromCache(array $files, bool $withFileAnalysis): array
    {
        $classNodes             = [];
        $fileAnalyses           = [];
        $anonymousClassNodes    = [];
        $fileReferences         = [];
        $fileInstantiations     = [];
        $functionNodes          = [];
        $anonymousFunctionNodes = [];
        $filesToParse           = [];

        foreach ($files as $file) {
            $cachedResult = $withFileAnalysis
                ? $this->analysisResultCache?->loadAnalysisNodesWithFileAnalysis(
                    $file,
                    $this->analysisNodeCacheNamespace
                )
                : $this->analysisResultCache?->loadAnalysisNodes($file, $this->analysisNodeCacheNamespace);

            if ($cachedResult === null) {
                $filesToParse[] = $file;
                continue;
            }

            array_push($classNodes, ...$cachedResult['classNodes']);
            array_push($anonymousClassNodes, ...$cachedResult['anonymousClassNodes']);
            array_push($functionNodes, ...$cachedResult['functionNodes']);
            array_push($anonymousFunctionNodes, ...$cachedResult['anonymousFunctionNodes']);

            $fileReferences[$file]     = $cachedResult['fileReferences'];
            $fileInstantiations[$file] = $cachedResult['fileInstantiations'];

            if (isset($cachedResult['fileAnalysis'])) {
                $fileAnalyses[$file] = $cachedResult['fileAnalysis'];
            }
        }

        $cachedResult = new ExtractionResult(
            $classNodes,
            $fileAnalyses,
            $anonymousClassNodes,
            $fileReferences,
            $fileInstantiations,
            $functionNodes,
            $anonymousFunctionNodes,
        );

        return [$cachedResult, $filesToParse];
    }
}
