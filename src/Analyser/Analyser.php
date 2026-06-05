<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\Analyser\Parallel\ParallelClassNodeExtractor;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Rule\LayerAwareRuleInterface;
use Boundwize\StructArmed\Rule\MultipleProjectRuleViolationInterface;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function array_walk;
use function count;
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

use const ARRAY_FILTER_USE_BOTH;

final class Analyser
{
    private readonly string $basePath;

    private readonly string $normalisedBasePath;

    /** @var array<string, string> */
    private array $normalisedPaths = [];

    public function __construct(
        string $basePath = '',
        private readonly ?AnalysisResultCache $analysisResultCache = null,
        private readonly string $classNodeCacheNamespace = '',
    ) {
        $this->basePath           = $basePath !== '' ? $basePath : (string) getcwd();
        $this->normalisedBasePath = $this->normalisePath($this->basePath);
    }

    /**
     * @param list<string>      $scanPaths
     * @param list<string>|null $files Pre-resolved file list; when provided, skips an internal filesForAnalysis() call.
     */
    public function analyse(
        Architecture $architecture,
        array $scanPaths = [],
        ?ProgressHandlerInterface $progressHandler = null,
        ?AnalyserOptions $analyserOptions = null,
        ?array $files = null
    ): RuleViolationCollection {
        $ruleViolationCollection = new RuleViolationCollection();
        $layers                  = $this->resolveLayers($architecture);
        $rules                   = $architecture->getRules();
        $ruleSkipPaths           = $architecture->getRuleSkipPaths();
        $skippedRuleKeys         = $this->skippedRuleKeyMap($architecture->getSkippedRuleKeys());
        $classRules              = $this->classRules($rules, $skippedRuleKeys);

        foreach ($rules as $key => $rule) {
            if (array_key_exists($key, $skippedRuleKeys)) {
                continue;
            }

            if (! $rule instanceof ProjectRuleInterface) {
                continue;
            }

            if ($rule instanceof MultipleProjectRuleViolationInterface) {
                $violations = $rule->evaluateProjectAll($this->basePath, $architecture, $ruleSkipPaths[$key] ?? []);
            } else {
                $single     = $rule->evaluateProject($this->basePath, $architecture, $ruleSkipPaths[$key] ?? []);
                $violations = $single instanceof RuleViolation ? [$single] : [];
            }

            foreach ($violations as $violation) {
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

        $layerPatterns      = $architecture->getLayerPatterns();
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig($layers, $this->basePath, $layerPatterns);

        $files    ??= $this->filesForAnalysis($architecture, $scanPaths, $layers);
        $classNodes = $this->collectClassNodes(
            $files,
            $progressHandler,
            $layers,
            $layerPatterns,
            $chainLayerResolver,
            $analyserOptions ?? AnalyserOptions::parallel()
        );

        // Evaluate declarative ruleset alongside class rules, but buffer its
        // violations so report ordering remains class rules before ruleset.
        $ruleset                    = $this->expandRuleset($architecture->getRuleset());
        $classViolationSkips        = $architecture->getClassViolationSkips();
        $rulesetSkipPaths           = $architecture->getRulesetSkipPaths();
        $rulesetViolationCollection = new RuleViolationCollection();
        $hasRuleset                 = $ruleset !== [];

        /** @var array<string, LayerAwareRuleInterface> $layerAwareRules */
        $layerAwareRules    = array_filter(
            $classRules,
            fn(RuleInterface $rule): bool => $rule instanceof LayerAwareRuleInterface
        );
        $hasLayerAwareRules = $layerAwareRules !== [];

        $classDependencyMaps      = $hasRuleset || $hasLayerAwareRules
            ? $this->classDependencyMaps($classNodes)
            : [
                'dependencies'            => [],
                'inheritanceDependencies' => [],
                'classLayerMap'           => [],
            ];
        $dependencyMap            = $classDependencyMaps['dependencies'];
        $inheritanceDependencyMap = $classDependencyMaps['inheritanceDependencies'];

        if ($hasLayerAwareRules) {
            array_walk(
                $layerAwareRules,
                fn($rule) => $rule->injectClassLayerMap($classDependencyMaps['classLayerMap'])
            );
        }

        foreach ($classNodes as $classNode) {
            foreach ($classRules as $key => $rule) {
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

            if (! $hasRuleset) {
                continue;
            }

            if ($classNode->layer === null) {
                continue;
            }

            if ($rulesetSkipPaths !== [] && $this->isSkipped($classNode->file, $rulesetSkipPaths)) {
                continue;
            }

            $allowedLayers = $ruleset[$classNode->layer] ?? null;

            if ($allowedLayers === null) {
                // Layer not listed in ruleset — no restriction.
                continue;
            }

            $skippedDepsForClass = $classViolationSkips[$classNode->className] ?? [];
            $dependencies        = $this->dependenciesForClass(
                $classNode->className,
                $dependencyMap,
                $inheritanceDependencyMap
            );

            foreach ($dependencies as $dependency) {
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

                $rulesetViolationCollection->add(new RuleViolation(
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

        $ruleViolationCollection->merge($rulesetViolationCollection);

        return $ruleViolationCollection;
    }

    /**
     * Expand `+LayerName` references in a ruleset into their concrete allowed layers.
     *
     * `+LayerName` means: include `LayerName` itself and all layers that `LayerName` is allowed to depend on.
     * References to unknown layers expand to nothing. Circular references are skipped.
     *
     * @param array<string, list<string>> $ruleset
     * @return array<string, list<string>>
     */
    private function expandRuleset(array $ruleset): array
    {
        $resolved = [];

        foreach ($ruleset as $layer => $allowedLayers) {
            $resolving        = [$layer => true];
            $resolved[$layer] = $this->expandRulesetLayer($allowedLayers, $ruleset, $resolving);
        }

        return $resolved;
    }

    /**
     * @param list<string>                $allowedLayers
     * @param array<string, list<string>> $ruleset
     * @param array<string, true>         $resolving  Layers currently being expanded (circular-reference guard).
     * @return list<string>
     */
    private function expandRulesetLayer(array $allowedLayers, array $ruleset, array $resolving): array
    {
        $expanded = [];

        foreach ($allowedLayers as $allowedLayer) {
            if (! str_starts_with($allowedLayer, '+')) {
                $expanded[] = $allowedLayer;
                continue;
            }

            $referencedLayer = substr($allowedLayer, 1);

            if (isset($resolving[$referencedLayer])) {
                continue;
            }

            // Include the referenced layer itself, then recursively its allowed layers.
            $expanded[]        = $referencedLayer;
            $referencedAllowed = $ruleset[$referencedLayer] ?? [];
            $expanded          = array_merge(
                $expanded,
                $this->expandRulesetLayer($referencedAllowed, $ruleset, $resolving + [$referencedLayer => true])
            );
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @param list<string> $skippedRuleKeys
     * @return array<string, true>
     */
    private function skippedRuleKeyMap(array $skippedRuleKeys): array
    {
        $keyMap = [];

        foreach ($skippedRuleKeys as $skippedRuleKey) {
            $keyMap[$skippedRuleKey] = true;
        }

        return $keyMap;
    }

    /**
     * @param array<string, ProjectRuleInterface|RuleInterface> $rules
     * @param array<string, true> $skippedRuleKeys
     * @return array<string, RuleInterface>
     */
    private function classRules(array $rules, array $skippedRuleKeys): array
    {
        return array_filter(
            $rules,
            static fn(ProjectRuleInterface|RuleInterface $rule, string $key): bool => $rule instanceof RuleInterface
                && ! array_key_exists($key, $skippedRuleKeys),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param list<ClassNode> $classNodes
     * @return array{
     *     dependencies: array<string, list<string>>,
     *     inheritanceDependencies: array<string, list<string>>,
     *     classLayerMap: array<string, string>
     * }
     */
    private function classDependencyMaps(array $classNodes): array
    {
        $dependencyMap            = [];
        $inheritanceDependencyMap = [];
        $classLayerMap            = [];

        foreach ($classNodes as $classNode) {
            $dependencyMap[$classNode->className] = $classNode->dependencies;
            $dependencies                         = $classNode->implements;

            if ($classNode->extends !== null) {
                $dependencies[] = $classNode->extends;
            }

            foreach ($classNode->traits as $trait) {
                $dependencies[] = $trait;
            }

            $inheritanceDependencyMap[$classNode->className] = array_values(array_unique($dependencies));

            if ($classNode->layer !== null) {
                $classLayerMap[$classNode->className] = $classNode->layer;
            }
        }

        return [
            'dependencies'            => $dependencyMap,
            'inheritanceDependencies' => $inheritanceDependencyMap,
            'classLayerMap'           => $classLayerMap,
        ];
    }

    /**
     * @param array<string, list<string>> $dependencyMap
     * @param array<string, list<string>> $inheritanceDependencyMap
     * @return list<string>
     */
    private function dependenciesForClass(
        string $className,
        array $dependencyMap,
        array $inheritanceDependencyMap
    ): array {
        $dependencies = [
            ...$dependencyMap[$className] ?? [],
            ...$this->dependenciesForInheritanceDependencies(
                $inheritanceDependencyMap[$className] ?? [],
                $dependencyMap,
                $inheritanceDependencyMap,
                [$className => true]
            ),
        ];

        return array_values(array_unique($dependencies));
    }

    /**
     * @param list<string>                $dependencies
     * @param array<string, list<string>> $dependencyMap
     * @param array<string, list<string>> $inheritanceDependencyMap
     * @param array<string, true>         $seen
     * @return list<string>
     */
    private function dependenciesForInheritanceDependencies(
        array $dependencies,
        array $dependencyMap,
        array $inheritanceDependencyMap,
        array $seen
    ): array {
        $resolvedDependencies = [];

        foreach ($dependencies as $dependency) {
            $resolvedDependencies[] = $dependency;

            if (isset($seen[$dependency])) {
                continue;
            }

            $resolvedDependencies = [
                ...$resolvedDependencies,
                ...$dependencyMap[$dependency] ?? [],
                ...$this->dependenciesForInheritanceDependencies(
                    $inheritanceDependencyMap[$dependency] ?? [],
                    $dependencyMap,
                    $inheritanceDependencyMap,
                    $seen + [$dependency => true]
                ),
            ];
        }

        return array_values(array_unique($resolvedDependencies));
    }

    /**
     * @param list<string> $files
     * @param array<string, string|list<string>> $layers
     * @param array<string, array<string, mixed>> $layerPatterns
     * @phpstan-param array<string, array{
     *     pattern: string|list<string>,
     *     excludePattern: string|list<string|null>|null
     * }> $layerPatterns
     * @return list<ClassNode>
     */
    private function collectClassNodes(
        array $files,
        ?ProgressHandlerInterface $progressHandler,
        array $layers,
        array $layerPatterns,
        ChainLayerResolver $chainLayerResolver,
        ?AnalyserOptions $analyserOptions = null
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

        $options = $analyserOptions ?? AnalyserOptions::parallel();

        if ($options->isParallel()) {
            $parsedClassNodes = (new ParallelClassNodeExtractor(
                $this->basePath,
                $layers,
                $layerPatterns,
                $options->workerCount,
                $this->analysisResultCache?->getCacheDirectory(),
            ))->extract($filesToParse, $progressHandler);
        } else {
            $parsedClassNodes = (new ClassNodeExtractor($chainLayerResolver))->extract($filesToParse, $progressHandler);
        }

        $classNodesByFile = array_fill_keys($filesToParse, []);
        foreach ($parsedClassNodes as $parsedClassNode) {
            $classNodes[] = $parsedClassNode;

            if (isset($classNodesByFile[$parsedClassNode->file])) {
                $classNodesByFile[$parsedClassNode->file][] = $parsedClassNode;
            }
        }

        foreach ($classNodesByFile as $fileToParse => $fileClassNodes) {
            $this->analysisResultCache?->storeClassNodes(
                $fileToParse,
                $this->classNodeCacheNamespace,
                $fileClassNodes
            );
        }

        $progressHandler?->finish();

        return $classNodes;
    }

    /**
     * @param list<string> $scanPaths
     * @param array<string, string|list<string>>|null $layers
     * @return list<string>
     */
    public function filesForAnalysis(Architecture $architecture, array $scanPaths = [], ?array $layers = null): array
    {
        return $this->collectPhpFiles(
            $layers ?? $this->resolveLayers($architecture),
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
            $isAbsolute = str_starts_with($layerPath, '/') || (strlen($layerPath) >= 2 && $layerPath[1] === ':');
            $fullPath   = $this->normalisePath(
                $isAbsolute
                    ? $layerPath
                    : rtrim($this->basePath, '/') . '/' . ltrim($layerPath, '/')
            );

            if (is_file($fullPath)) {
                if (str_ends_with($fullPath, '.php') && ! $this->isSkipped($fullPath, $skipPaths)) {
                    $files[] = $fullPath;
                }

                continue;
            }

            if (! is_dir($fullPath)) {
                continue;
            }

            if ($this->isSkipped($fullPath, $skipPaths)) {
                continue;
            }

            foreach ($this->phpFiles($fullPath, $skipPaths) as $file) {
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

        foreach ($layers as $layerName => $layerPaths) {
            if ($layerName === 'Source' && $layerPaths === []) {
                $layers[$layerName] = (new Psr4PathResolver())->paths($this->basePath);
                break;
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
        if ($skipPaths === []) {
            return false;
        }

        $normalisedPath = $this->normalisePath($path);

        foreach ($skipPaths as $skipPath) {
            $absoluteSkipPath = $this->toAbsoluteSkipPath($this->normaliseSkipPath($skipPath));

            if ($this->matchesSkipPath($normalisedPath, $absoluteSkipPath)) {
                return true;
            }
        }

        return false;
    }

    private function toAbsoluteSkipPath(string $normalisedSkipPath): string
    {
        if (
            str_starts_with($normalisedSkipPath, '/')
            || (strlen($normalisedSkipPath) >= 2 && $normalisedSkipPath[1] === ':')
        ) {
            return $normalisedSkipPath;
        }

        return $this->normalisedBasePath . '/' . $normalisedSkipPath;
    }

    private function matchesSkipPath(string $normalisedPath, string $absoluteSkipPath): bool
    {
        if (strpbrk($absoluteSkipPath, '*?[') !== false) {
            return fnmatch($absoluteSkipPath, $normalisedPath);
        }

        $resolvedSkipPath = $this->normalisePath($absoluteSkipPath);

        return $normalisedPath === $resolvedSkipPath
            || str_starts_with($normalisedPath, $resolvedSkipPath . '/');
    }

    private function normaliseSkipPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function normalisePath(string $path): string
    {
        return $this->normalisedPaths[$path] ??= rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
    }

    /**
     * @param list<string> $skipPaths
     * @return string[]
     */
    private function phpFiles(string $path, array $skipPaths): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $file) use ($skipPaths): bool {
                    $isRealDirectory = $file->isDir() && ! $file->isLink();
                    if (! $isRealDirectory && $file->getExtension() !== 'php') {
                        return false;
                    }

                    return ! $this->isSkipped($file->getPathname(), $skipPaths);
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
