<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\ClassNameRegexLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function fnmatch;
use function getcwd;
use function in_array;
use function is_dir;
use function is_file;
use function ltrim;
use function realpath;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function substr;

final readonly class Analyser
{
    private string $basePath;

    public function __construct(
        string $basePath = '',
        private ?AnalysisResultCache $analysisResultCache = null,
        private string $classNodeCacheNamespace = '',
    ) {
        $this->basePath = $basePath !== '' ? $basePath : (string) getcwd();
    }

    /**
     * @param list<string> $scanPaths
     */
    public function analyse(
        Architecture $architecture,
        array $scanPaths = [],
        ?ProgressHandlerInterface $progressHandler = null
    ): RuleViolationCollection {
        $ruleViolationCollection = new RuleViolationCollection();
        $layers                  = $this->resolveLayers($architecture);
        $ruleSkipPaths           = $architecture->getRuleSkipPaths();
        $skippedRuleKeys         = $architecture->getSkippedRuleKeys();

        foreach ($architecture->getRules() as $key => $rule) {
            if (in_array($key, $skippedRuleKeys, true)) {
                continue;
            }

            if (! $rule instanceof ProjectRuleInterface) {
                continue;
            }

            $violation = $rule->evaluateProject($this->basePath, $architecture, $ruleSkipPaths[$key] ?? []);

            if (! $violation instanceof RuleViolation) {
                continue;
            }

            $ruleViolationCollection->add(new RuleViolation(
                message:   $violation->message,
                file:      $violation->file,
                line:      $violation->line,
                className: $violation->className,
                layer:     $violation->layer,
                ruleKey:   $key,
            ));
        }

        $layerPatterns      = $architecture->getLayerPatterns();
        $chainLayerResolver = $layerPatterns !== []
            ? new ChainLayerResolver(
                new ClassNameRegexLayerResolver($layerPatterns),
                new NamespaceLayerResolver($layers, $this->basePath)
            )
            : new ChainLayerResolver(
                new NamespaceLayerResolver($layers, $this->basePath)
            );

        $files      = $this->filesForAnalysis($architecture, $scanPaths);
        $classNodes = $this->collectClassNodes($chainLayerResolver, $files, $progressHandler);

        $rules = $architecture->getRules();

        foreach ($classNodes as $classNode) {
            foreach ($rules as $key => $rule) {
                if (in_array($key, $skippedRuleKeys, true)) {
                    continue;
                }

                if (! $rule instanceof RuleInterface) {
                    continue;
                }

                if ($this->isSkipped($classNode->file, $ruleSkipPaths[$key] ?? [])) {
                    continue;
                }

                if (! $rule->appliesTo($classNode)) {
                    continue;
                }

                $violations = $rule instanceof MultipleRuleViolationInterface
                    ? $rule->evaluateAll($classNode)
                    : [$rule->evaluate($classNode)];

                foreach ($violations as $violation) {
                    if (! $violation instanceof RuleViolation) {
                        continue;
                    }

                    // Inject the rule key into the violation
                    $ruleViolationCollection->add(new RuleViolation(
                        message:   $violation->message,
                        file:      $violation->file,
                        line:      $violation->line,
                        className: $violation->className,
                        layer:     $violation->layer,
                        ruleKey:   $key,
                    ));
                }
            }
        }

        // Evaluate declarative ruleset.
        // For each layer listed as a key in the ruleset, any dependency that resolves
        // to a different *defined* layer and is not in the allowed list is a violation.
        $ruleset             = $architecture->getRuleset();
        $classViolationSkips = $architecture->getClassViolationSkips();

        if ($ruleset !== []) {
            foreach ($classNodes as $classNode) {
                if ($classNode->layer === null) {
                    continue;
                }

                $allowedLayers = $ruleset[$classNode->layer] ?? null;

                if ($allowedLayers === null) {
                    // Layer not listed in ruleset — no restriction.
                    continue;
                }

                $skippedDepsForClass = $classViolationSkips[$classNode->className] ?? [];

                foreach ($classNode->dependencies as $dependency) {
                    if (in_array($dependency, $skippedDepsForClass, true)) {
                        continue;
                    }

                    $depLayer = $chainLayerResolver->resolve($dependency, '');

                    if ($depLayer === null) {
                        // External / unregistered dependency — not restricted.
                        continue;
                    }

                    if ($depLayer === $classNode->layer) {
                        // Same-layer dependency — always allowed.
                        continue;
                    }

                    if (in_array($depLayer, $allowedLayers, true)) {
                        continue;
                    }

                    $ruleViolationCollection->add(new RuleViolation(
                        message:   sprintf(
                            'Class [%s] in layer [%s] must not depend on [%s] which belongs to layer [%s]',
                            $classNode->className,
                            $classNode->layer,
                            $dependency,
                            $depLayer
                        ),
                        file:      $classNode->file,
                        line:      $classNode->line,
                        className: $classNode->className,
                        layer:     $classNode->layer,
                        ruleKey:   'ruleset.' . $classNode->layer,
                    ));
                }
            }
        }

        return $ruleViolationCollection;
    }

    /**
     * @param list<string> $files
     * @return list<ClassNode>
     */
    private function collectClassNodes(
        ChainLayerResolver $chainLayerResolver,
        array $files,
        ?ProgressHandlerInterface $progressHandler
    ): array {
        $classNodes   = [];
        $filesToParse = [];

        foreach ($files as $file) {
            $cachedClassNodes = $this->analysisResultCache?->loadClassNodes($file, $this->classNodeCacheNamespace);

            if ($cachedClassNodes === null) {
                $filesToParse[] = $file;
                continue;
            }

            foreach ($cachedClassNodes as $cachedClassNode) {
                $classNodes[] = $cachedClassNode;
            }
        }

        $progressHandler?->start(count($filesToParse));

        if ($filesToParse === []) {
            $progressHandler?->finish();

            return $classNodes;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        foreach ($filesToParse as $fileToParse) {
            $fileClassNodes = [];

            try {
                $classCollector = new ClassCollector($chainLayerResolver);
                $code           = (string) file_get_contents($fileToParse);
                $ast            = $parser->parse($code);

                if ($ast === null || $ast === []) {
                    continue;
                }

                $classCollector->setCurrentFile($fileToParse);

                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver());
                $traverser->addVisitor($classCollector);
                $traverser->traverse($ast);

                $fileClassNodes = array_values($classCollector->getNodes());

                foreach ($fileClassNodes as $fileClassNode) {
                    $classNodes[] = $fileClassNode;
                }
            } catch (Error) {
                // Skip files with parse errors
            } finally {
                $this->analysisResultCache?->storeClassNodes(
                    $fileToParse,
                    $this->classNodeCacheNamespace,
                    $fileClassNodes
                );
                $progressHandler?->advance($fileToParse);
            }
        }

        $progressHandler?->finish();

        return $classNodes;
    }

    /**
     * @param list<string> $scanPaths
     * @return list<string>
     */
    public function filesForAnalysis(Architecture $architecture, array $scanPaths = []): array
    {
        return $this->collectPhpFiles(
            $this->resolveLayers($architecture),
            $scanPaths,
            $architecture->getSkipPaths()
        );
    }

    /**
     * @param array<string, string|list<string>> $layers
     * @param list<string> $scanPaths
     * @param list<string> $skipPaths
     * @return list<string>
     */
    private function collectPhpFiles(array $layers, array $scanPaths, array $skipPaths): array
    {
        $files = [];

        foreach ($this->scanPaths($layers, $scanPaths) as $layerPath) {
            $fullPath = rtrim($this->basePath, '/') . '/' . ltrim($layerPath, '/');

            if (is_file($fullPath)) {
                if (str_ends_with($fullPath, '.php') && ! $this->isSkipped($fullPath, $skipPaths)) {
                    $realPath = realpath($fullPath);

                    if ($realPath !== false) {
                        $files[] = $realPath;
                    }
                }

                continue;
            }

            if (! is_dir($fullPath)) {
                continue;
            }

            if ($this->isSkipped($fullPath, $skipPaths)) {
                continue;
            }

            foreach ($this->phpFiles($fullPath) as $file) {
                if ($this->isSkipped($file, $skipPaths)) {
                    continue;
                }

                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function resolveLayers(Architecture $architecture): array
    {
        $layers = $architecture->getLayers();

        foreach ($architecture->getRules() as $rule) {
            if (
                $rule instanceof Psr4SourcePathsRule
                && ($layers[Psr4Preset::SOURCE_LAYER] ?? null) === []
            ) {
                $layers[Psr4Preset::SOURCE_LAYER] = $rule->sourcePathsFor($this->basePath);
            }
        }

        return $layers;
    }

    /**
     * @param array<string, string|list<string>> $layers
     * @param list<string> $scanPaths
     * @return list<string>
     */
    private function scanPaths(array $layers, array $scanPaths): array
    {
        if ($scanPaths !== []) {
            return array_values(array_unique($scanPaths));
        }

        $paths = [];

        foreach ($layers as $layer) {
            foreach ((array) $layer as $layerPath) {
                $paths[] = $layerPath;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $skipPaths
     */
    private function isSkipped(string $path, array $skipPaths): bool
    {
        $path         = $this->normalisePath($path);
        $relativePath = $this->relativePath($path);

        foreach ($skipPaths as $skipPath) {
            if (
                $this->matchesSkipPath($relativePath, $skipPath)
                || $this->matchesSkipPath($path, $skipPath)
            ) {
                return true;
            }
        }

        return false;
    }

    private function matchesSkipPath(string $path, string $skipPath): bool
    {
        $skipPath = $this->normaliseSkipPath($skipPath);

        if (fnmatch($skipPath, $path)) {
            return true;
        }

        if (strpbrk($skipPath, '*?[') !== false) {
            return false;
        }

        $fullSkipPath = rtrim($this->basePath, '/') . '/' . ltrim($skipPath, '/');
        $fullSkipPath = $this->normalisePath($fullSkipPath);

        $normalisedPath = $this->normalisePath($path);

        return $normalisedPath === $fullSkipPath
            || str_starts_with($normalisedPath, $fullSkipPath . '/')
            || $path === $skipPath
            || str_starts_with($path, rtrim($skipPath, '/') . '/');
    }

    private function normaliseSkipPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function relativePath(string $path): string
    {
        $basePath = $this->normalisePath($this->basePath);

        if ($path === $basePath) {
            return '';
        }

        if (str_starts_with($path, $basePath . '/')) {
            return substr($path, strlen($basePath) + 1);
        }

        return $path;
    }

    private function normalisePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
    }

    /**
     * @return string[]
     */
    private function phpFiles(string $path): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $realPath = $file->getRealPath();

            if ($file->getExtension() === 'php' && $realPath !== false) {
                $files[] = $realPath;
            }
        }

        return $files;
    }
}
