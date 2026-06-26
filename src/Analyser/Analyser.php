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
use Boundwize\StructArmed\Rule\FileAnalysisRuleInterface;
use Boundwize\StructArmed\Rule\LayerAwareRuleInterface;
use Boundwize\StructArmed\Rule\MultipleProjectRuleViolationInterface;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Util\Path;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_fill_keys;
use function array_filter;
use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function fnmatch;
use function getcwd;
use function in_array;
use function is_dir;
use function is_file;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strpbrk;
use function substr;

use const ARRAY_FILTER_USE_BOTH;

final readonly class Analyser
{
    private string $basePath;

    private string $normalisedBasePath;

    public function __construct(
        string $basePath = '',
        private ?AnalysisResultCache $analysisResultCache = null,
        private string $classNodeCacheNamespace = '',
    ) {
        $this->basePath           = $basePath !== '' ? $basePath : (string) getcwd();
        $this->normalisedBasePath = Path::normalise($this->basePath, canonicalise: true);
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

        $layers           = $this->resolveLayers($architecture);
        $rules            = $architecture->getRules();
        $ruleSkipPaths    = $architecture->getRuleSkipPaths();
        $skippedRuleKeys  = $this->skippedRuleKeyMap($architecture->getSkippedRuleKeys());
        $classRules       = $this->classRules($rules, $skippedRuleKeys);
        $ruleSkipMatchers = $this->ruleSkipMatchers($classRules, $ruleSkipPaths);

        $projectRuleViolations = [];
        $fileAnalysisRules     = [];
        $layerAwareRules       = [];

        foreach ($rules as $key => $rule) {
            if (array_key_exists($key, $skippedRuleKeys)) {
                continue;
            }

            if ($rule instanceof LayerAwareRuleInterface) {
                $layerAwareRules[] = $rule;
            }

            if (! $rule instanceof ProjectRuleInterface) {
                continue;
            }

            if ($rule instanceof FileAnalysisRuleInterface) {
                $fileAnalysisRules[$key] = $rule;
                continue;
            }

            if ($rule instanceof MultipleProjectRuleViolationInterface) {
                $projectRuleViolations[$key] = $rule->evaluateProjectAll(
                    $this->basePath,
                    $architecture,
                    $ruleSkipPaths[$key] ?? []
                );
            } else {
                $single = $rule->evaluateProject($this->basePath, $architecture, $ruleSkipPaths[$key] ?? []);

                $projectRuleViolations[$key] = $single instanceof RuleViolation ? [$single] : [];
            }
        }

        $layerPatterns      = $architecture->getLayerPatterns();
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig($layers, $this->basePath, $layerPatterns);

        $files          ??= $this->filesForAnalysis($architecture, $scanPaths, $layers);
        $extractionResult = $this->collectClassNodes(
            $files,
            $progressHandler,
            $layers,
            $layerPatterns,
            $chainLayerResolver,
            $analyserOptions ?? AnalyserOptions::parallel(),
            $fileAnalysisRules !== [],
        );
        $classNodes       = $extractionResult->classNodes;
        $classNodes       = $this->withRecursiveParents($classNodes);

        $fileAnalysisProvider = new FileAnalysisProvider($extractionResult->fileAnalyses);

        foreach ($fileAnalysisRules as $key => $rule) {
            $projectRuleViolations[$key] = $rule->evaluateProjectAllWithProvider(
                $this->basePath,
                $architecture,
                $fileAnalysisProvider,
                $ruleSkipPaths[$key] ?? [],
            );
        }

        foreach ($rules as $key => $rule) {
            if (! $rule instanceof ProjectRuleInterface || ! isset($projectRuleViolations[$key])) {
                continue;
            }

            foreach ($projectRuleViolations[$key] as $violation) {
                $ruleViolationCollection->add(new RuleViolation(
                    message:   $violation->message,
                    file:      $violation->file,
                    line:      $violation->line,
                    className: $violation->className,
                    layer:     $violation->layer,
                    ruleKey:   $key,
                    methodName: $violation->methodName,
                    constantName: $violation->constantName,
                    propertyName: $violation->propertyName,
                ));
            }
        }

        // Evaluate declarative ruleset alongside class rules, but buffer its
        // violations so report ordering remains class rules before ruleset.
        $ruleset                    = $this->expandRuleset($architecture->getRuleset());
        $classViolationSkips        = $architecture->getClassViolationSkips();
        $rulesetSkipPaths           = $architecture->getRulesetSkipPaths();
        $rulesetSkipMatchers        = $this->compileSkipMatchers($rulesetSkipPaths);
        $rulesetViolationCollection = new RuleViolationCollection();
        $hasRuleset                 = $ruleset !== [];
        $scanScopeLayerMap          = $hasRuleset ? $this->scanScopeLayerMap($architecture) : [];

        $hasLayerAwareRules = $layerAwareRules !== [];

        $classDependencyMaps      = $hasRuleset || $hasLayerAwareRules
            ? $this->classDependencyMaps($classNodes, $hasRuleset, $hasLayerAwareRules)
            : [
                'dependencies'            => [],
                'inheritanceDependencies' => [],
                'classLayerMap'           => [],
                'classPrimaryLayerMap'    => [],
                'classNodeMap'            => [],
            ];
        $dependencyMap            = $classDependencyMaps['dependencies'];
        $inheritanceDependencyMap = $classDependencyMaps['inheritanceDependencies'];

        $resolvedInheritedDependencies = [];

        foreach ($layerAwareRules as $rule) {
            $rule->injectClassNodeMap($classDependencyMaps['classNodeMap']);
        }

        foreach ($classNodes as $classNode) {
            foreach ($classRules as $key => $rule) {
                if ($this->isSkipped($classNode->file, $ruleSkipMatchers[$key])) {
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
                        methodName: $violation->methodName,
                        constantName: $violation->constantName,
                        propertyName: $violation->propertyName,
                    ));
                }
            }

            if (! $hasRuleset) {
                continue;
            }

            if ($classNode->layer === null) {
                continue;
            }

            if ($rulesetSkipPaths !== [] && $this->isSkipped($classNode->file, $rulesetSkipMatchers)) {
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
                $inheritanceDependencyMap,
                $resolvedInheritedDependencies
            );

            foreach ($dependencies as $dependency) {
                if (in_array($dependency, $skippedDepsForClass, true)) {
                    continue;
                }

                $primaryLayer = $classDependencyMaps['classPrimaryLayerMap'][$dependency] ?? null;
                $regexLayers  = $chainLayerResolver->resolveAll($dependency, '');

                if ($regexLayers !== []) {
                    $depLayers = $regexLayers;
                } elseif ($primaryLayer !== null && ! array_key_exists($primaryLayer, $scanScopeLayerMap)) {
                    // Scanned dep in a specific path-based layer (not a PSR4 catch-all).
                    $depLayers = $classDependencyMaps['classLayerMap'][$dependency] ?? [$primaryLayer];
                } else {
                    $depLayers = [];
                }

                if ($depLayers === []) {
                    // External / unregistered dependency — not restricted.
                    continue;
                }

                $isSameLayer = $primaryLayer !== null
                    ? $primaryLayer === $classNode->layer
                    : in_array($classNode->layer, $depLayers, true);

                if ($isSameLayer) {
                    continue;
                }

                if ($primaryLayer !== null) {
                    // Scanned dependency: permitted if any of its layers is explicitly allowed.
                    if (array_intersect($allowedLayers, $depLayers) !== []) {
                        continue;
                    }

                    $violatingLayer = $primaryLayer;
                } else {
                    // Unscanned/regex-resolved dependency: report the first non-allowed layer.
                    $violatingLayer = null;

                    foreach ($depLayers as $depLayer) {
                        if (! in_array($depLayer, $allowedLayers, true)) {
                            $violatingLayer = $depLayer;
                            break;
                        }
                    }

                    if ($violatingLayer === null) {
                        continue;
                    }
                }

                $rulesetViolationCollection->add(new RuleViolation(
                    message:   sprintf(
                        'Class [%s] in layer [%s] must not depend on [%s] which belongs to layer [%s]',
                        $classNode->className,
                        $classNode->layer,
                        $dependency,
                        $violatingLayer
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
     * Empty layer paths are PSR-4 scan scopes resolved from composer.json, not
     * ruleset dependency layers. Classes found only through these layers are
     * treated like external dependencies during ruleset checks.
     *
     * @return array<string, true>
     */
    private function scanScopeLayerMap(Architecture $architecture): array
    {
        $scanScopeLayerMap = [];

        foreach ($architecture->getLayers() as $layerName => $layerPaths) {
            if ($layerPaths === []) {
                $scanScopeLayerMap[$layerName] = true;
            }
        }

        return $scanScopeLayerMap;
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
     * @param array<string, RuleInterface> $classRules
     * @param array<string, list<string>>  $ruleSkipPaths
     * @return array<string, array{paths: list<string>, patterns: list<string>}>
     */
    private function ruleSkipMatchers(array $classRules, array $ruleSkipPaths): array
    {
        $ruleSkipMatchers = [];

        foreach (array_keys($classRules) as $key) {
            $ruleSkipMatchers[$key] = $this->compileSkipMatchers($ruleSkipPaths[$key] ?? []);
        }

        return $ruleSkipMatchers;
    }

    /**
     * @param list<ClassNode> $classNodes
     * @return array{
     *     dependencies: array<string, list<string>>,
     *     inheritanceDependencies: array<string, list<string>>,
     *     classLayerMap: array<string, list<string>>,
     *     classPrimaryLayerMap: array<string, string>,
     *     classNodeMap: array<string, ClassNode>
     * }
     */
    private function classDependencyMaps(
        array $classNodes,
        bool $collectRulesetMaps,
        bool $collectClassNodeMap,
    ): array {
        $dependencyMap            = [];
        $inheritanceDependencyMap = [];
        $classLayerMap            = [];
        $classPrimaryLayerMap     = [];
        $classNodeMap             = [];

        foreach ($classNodes as $classNode) {
            if ($collectClassNodeMap) {
                $classNodeMap[$classNode->className] = $classNode;
            }

            if (! $collectRulesetMaps) {
                continue;
            }

            $dependencyMap[$classNode->className] = $classNode->dependencies;
            $dependencies                         = [
                ...$classNode->implements,
                ...$classNode->interfaceExtends,
            ];

            if ($classNode->extends !== null) {
                $dependencies[] = $classNode->extends;
            }

            foreach ($classNode->traits as $trait) {
                $dependencies[] = $trait;
            }

            $inheritanceDependencyMap[$classNode->className] = array_values(array_unique($dependencies));

            if ($classNode->layers !== []) {
                $classLayerMap[$classNode->className] = $classNode->layers;
            }

            if ($classNode->layer !== null) {
                $classPrimaryLayerMap[$classNode->className] = $classNode->layer;
            }
        }

        return [
            'dependencies'            => $dependencyMap,
            'inheritanceDependencies' => $inheritanceDependencyMap,
            'classLayerMap'           => $classLayerMap,
            'classPrimaryLayerMap'    => $classPrimaryLayerMap,
            'classNodeMap'            => $classNodeMap,
        ];
    }

    /**
     * @param array<string, list<string>> $dependencyMap
     * @param array<string, list<string>> $inheritanceDependencyMap
     * @param array<string, list<string>> $resolvedInheritedDependencies
     * @return list<string>
     */
    private function dependenciesForClass(
        string $className,
        array $dependencyMap,
        array $inheritanceDependencyMap,
        array &$resolvedInheritedDependencies
    ): array {
        $dependencies = $dependencyMap[$className] ?? [];

        foreach ($inheritanceDependencyMap[$className] ?? [] as $dependency) {
            $cycleDetected = false;
            $dependencies  = [
                ...$dependencies,
                ...$this->dependenciesForInheritanceDependency(
                    $dependency,
                    $dependencyMap,
                    $inheritanceDependencyMap,
                    $resolvedInheritedDependencies,
                    [$className => true],
                    $cycleDetected
                ),
            ];
        }

        return array_values(array_unique($dependencies));
    }

    /**
     * @param array<string, list<string>> $dependencyMap
     * @param array<string, list<string>> $inheritanceDependencyMap
     * @param array<string, list<string>> $resolvedInheritedDependencies
     * @param array<string, true>         $seen
     * @return list<string>
     */
    private function dependenciesForInheritanceDependency(
        string $dependency,
        array $dependencyMap,
        array $inheritanceDependencyMap,
        array &$resolvedInheritedDependencies,
        array $seen,
        bool &$cycleDetected
    ): array {
        $resolvedDependencies = [$dependency];

        if (isset($seen[$dependency])) {
            $cycleDetected = true;

            return $resolvedDependencies;
        }

        if (isset($resolvedInheritedDependencies[$dependency])) {
            return $resolvedInheritedDependencies[$dependency];
        }

        $hasCycle             = false;
        $resolvedDependencies = [
            ...$resolvedDependencies,
            ...$dependencyMap[$dependency] ?? [],
        ];
        $seen                += [$dependency => true];

        foreach ($inheritanceDependencyMap[$dependency] ?? [] as $inheritedDependency) {
            $childHasCycle        = false;
            $resolvedDependencies = [
                ...$resolvedDependencies,
                ...$this->dependenciesForInheritanceDependency(
                    $inheritedDependency,
                    $dependencyMap,
                    $inheritanceDependencyMap,
                    $resolvedInheritedDependencies,
                    $seen,
                    $childHasCycle
                ),
            ];
            $hasCycle             = $hasCycle || $childHasCycle;
        }

        $resolvedDependencies = array_values(array_unique($resolvedDependencies));

        if (! $hasCycle) {
            $resolvedInheritedDependencies[$dependency] = $resolvedDependencies;
        }

        $cycleDetected = $cycleDetected || $hasCycle;

        return $resolvedDependencies;
    }

    /**
     * @param list<ClassNode> $classNodes
     * @return list<ClassNode>
     */
    private function withRecursiveParents(array $classNodes): array
    {
        $parentClassMap     = [];
        $parentInterfaceMap = [];
        $parentsCache       = [];

        foreach ($classNodes as $classNode) {
            $parentClassMap[$classNode->className]     = $classNode->extends !== null
                ? [$classNode->extends]
                : [];
            $parentInterfaceMap[$classNode->className] = $classNode->interfaceExtends !== []
                ? array_values(array_unique([...$classNode->implements, ...$classNode->interfaceExtends]))
                : array_values($classNode->implements);
        }

        foreach ($classNodes as $classNode) {
            if (
                $parentClassMap[$classNode->className] === []
                && $parentInterfaceMap[$classNode->className] === []
            ) {
                continue;
            }

            $cycleDetected = false;
            $result        = $this->recursiveParents(
                $classNode->className,
                $parentClassMap,
                $parentInterfaceMap,
                $parentsCache,
                [$classNode->className => true],
                $cycleDetected
            );

            $classNode->setRecursiveParents($result['classes'], $result['interfaces']);
        }

        return $classNodes;
    }

    /**
     * Single DFS that collects both ancestor classes and transitively implemented/extended
     * interfaces in one pass, avoiding the double traversal of the parent-class chain that
     * the previous two-method approach required.
     *
     * @param array<string, list<string>>                                        $parentClassMap
     * @param array<string, list<string>>                                        $parentInterfaceMap
     * @param array<string, array{classes: list<string>, interfaces: list<string>}> $cache
     * @param array<string, true>                                                $seen
     * @return array{classes: list<string>, interfaces: list<string>}
     */
    private function recursiveParents(
        string $className,
        array $parentClassMap,
        array $parentInterfaceMap,
        array &$cache,
        array $seen,
        bool &$cycleDetected
    ): array {
        if (isset($cache[$className])) {
            return $cache[$className];
        }

        $classesSet    = [];
        $interfacesSet = [];
        $hasCycle      = false;

        foreach ($parentClassMap[$className] ?? [] as $parentClass) {
            if (isset($seen[$parentClass])) {
                $hasCycle = true;
                continue;
            }

            $childHasCycle            = false;
            $classesSet[$parentClass] = true;
            $result                   = $this->recursiveParents(
                $parentClass,
                $parentClassMap,
                $parentInterfaceMap,
                $cache,
                $seen + [$parentClass => true],
                $childHasCycle
            );

            foreach ($result['classes'] as $ancestor) {
                $classesSet[$ancestor] = true;
            }

            foreach ($result['interfaces'] as $iface) {
                $interfacesSet[$iface] = true;
            }

            $hasCycle = $hasCycle || $childHasCycle;
        }

        foreach ($parentInterfaceMap[$className] ?? [] as $parentInterface) {
            if (isset($seen[$parentInterface])) {
                $hasCycle = true;
                continue;
            }

            $childHasCycle                   = false;
            $interfacesSet[$parentInterface] = true;
            $result                          = $this->recursiveParents(
                $parentInterface,
                $parentClassMap,
                $parentInterfaceMap,
                $cache,
                $seen + [$parentInterface => true],
                $childHasCycle
            );

            foreach ($result['interfaces'] as $ancestor) {
                $interfacesSet[$ancestor] = true;
            }

            $hasCycle = $hasCycle || $childHasCycle;
        }

        $result = [
            'classes'    => array_keys($classesSet),
            'interfaces' => array_keys($interfacesSet),
        ];

        if (! $hasCycle) {
            $cache[$className] = $result;
        }

        $cycleDetected = $cycleDetected || $hasCycle;

        return $result;
    }

    /**
     * @param list<string> $files
     * @param array<string, string|list<string>> $layers
     * @param array<string, array<string, mixed>> $layerPatterns
     * @phpstan-param array<string, array{
     *     pattern: string|list<string>,
     *     excludePattern: string|list<string|null>|null
     * }> $layerPatterns
     */
    private function collectClassNodes(
        array $files,
        ?ProgressHandlerInterface $progressHandler,
        array $layers,
        array $layerPatterns,
        ChainLayerResolver $chainLayerResolver,
        ?AnalyserOptions $analyserOptions = null,
        bool $withFileAnalysis = true,
    ): ExtractionResult {
        $classNodes   = [];
        $fileAnalyses = [];
        $filesToParse = [];

        foreach ($files as $file) {
            if ($withFileAnalysis) {
                $cachedResult = $this->analysisResultCache?->loadClassNodesWithFileAnalysis(
                    $file,
                    $this->classNodeCacheNamespace
                );

                if ($cachedResult === null) {
                    $filesToParse[] = $file;
                    continue;
                }

                foreach ($cachedResult['classNodes'] as $cachedClassNode) {
                    $classNodes[] = $cachedClassNode;
                }

                $fileAnalyses[$file] = $cachedResult['fileAnalysis'];

                continue;
            }

            $cachedClassNodes = $this->analysisResultCache?->loadClassNodes(
                $file,
                $this->classNodeCacheNamespace,
            );

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

            return new ExtractionResult($classNodes, $fileAnalyses);
        }

        $options = $analyserOptions ?? AnalyserOptions::parallel();

        if ($options->isParallel()) {
            $parsedResult = (new ParallelClassNodeExtractor(
                $this->basePath,
                $layers,
                $layerPatterns,
                $options->workerCount,
                $this->analysisResultCache?->getCacheDirectory(),
            ))->extract($filesToParse, $progressHandler, $withFileAnalysis);
        } else {
            $parsedResult = (new ClassNodeExtractor($chainLayerResolver))->extract(
                $filesToParse,
                $progressHandler,
                $withFileAnalysis,
            );
        }

        $classNodesByFile = array_fill_keys($filesToParse, []);
        foreach ($parsedResult->classNodes as $parsedClassNode) {
            $classNodes[] = $parsedClassNode;

            if (isset($classNodesByFile[$parsedClassNode->file])) {
                $classNodesByFile[$parsedClassNode->file][] = $parsedClassNode;
            }
        }

        foreach ($parsedResult->fileAnalyses as $file => $fileAnalysis) {
            $fileAnalyses[$file] = $fileAnalysis;
        }

        foreach ($classNodesByFile as $fileToParse => $fileClassNodes) {
            $this->analysisResultCache?->storeClassNodes(
                $fileToParse,
                $this->classNodeCacheNamespace,
                $fileClassNodes,
                $fileAnalyses[$fileToParse] ?? null,
            );
        }

        $progressHandler?->finish();

        return new ExtractionResult($classNodes, $fileAnalyses);
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
        $files        = [];
        $skipMatchers = $this->compileSkipMatchers($skipPaths);

        foreach ($this->scanPaths($layers, $scanPaths) as $layerPath) {
            $fullPath = Path::normalise(
                Path::resolve($layerPath, $this->basePath),
                canonicalise: true
            );

            if (is_file($fullPath)) {
                if (str_ends_with($fullPath, '.php') && ! $this->isSkipped($fullPath, $skipMatchers)) {
                    $files[] = $fullPath;
                }

                continue;
            }

            if (! is_dir($fullPath)) {
                continue;
            }

            if ($this->isSkipped($fullPath, $skipMatchers)) {
                continue;
            }

            foreach ($this->phpFiles($fullPath, $skipMatchers) as $file) {
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
     * @return array{paths: list<string>, patterns: list<string>}
     */
    private function compileSkipMatchers(array $skipPaths): array
    {
        $skipMatchers = [
            'paths'    => [],
            'patterns' => [],
        ];

        foreach ($skipPaths as $skipPath) {
            $absoluteSkipPath = Path::resolve(
                Path::normalise($skipPath),
                $this->normalisedBasePath
            );

            if (strpbrk($absoluteSkipPath, '*?[') !== false) {
                $skipMatchers['patterns'][] = $absoluteSkipPath;

                continue;
            }

            $skipMatchers['paths'][] = Path::normalise($absoluteSkipPath, canonicalise: true);
        }

        return $skipMatchers;
    }

    /**
     * @param array{paths: list<string>, patterns: list<string>} $skipMatchers
     */
    private function isSkipped(string $path, array $skipMatchers): bool
    {
        if ($skipMatchers['paths'] === [] && $skipMatchers['patterns'] === []) {
            return false;
        }

        $normalisedPath = Path::normalise($path, canonicalise: true);

        foreach ($skipMatchers['paths'] as $skipPath) {
            if ($normalisedPath === $skipPath || str_starts_with($normalisedPath, $skipPath . '/')) {
                return true;
            }
        }

        foreach ($skipMatchers['patterns'] as $skipPattern) {
            if (fnmatch($skipPattern, $normalisedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{paths: list<string>, patterns: list<string>} $skipMatchers
     * @return string[]
     */
    private function phpFiles(string $path, array $skipMatchers): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $file) use ($skipMatchers): bool {
                    $isRealDirectory = $file->isDir() && ! $file->isLink();
                    if (! $isRealDirectory && $file->getExtension() !== 'php') {
                        return false;
                    }

                    return ! $this->isSkipped($file->getPathname(), $skipMatchers);
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $files[] = Path::normalise($file->getPathname(), canonicalise: true);
        }

        return $files;
    }
}
