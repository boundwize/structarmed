<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\Analyser\Parallel\ParallelClassNodeExtractor;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\Composer\Psr4PathResolver;
use Boundwize\StructArmed\File\PhpFileCollector;
use Boundwize\StructArmed\File\SkipPathMatcher;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Rule\ComposerJsonRuleInterface;
use Boundwize\StructArmed\Rule\ExtendedClassAwareRuleInterface;
use Boundwize\StructArmed\Rule\FileAnalysisRuleInterface;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\LayerAwareRuleInterface;
use Boundwize\StructArmed\Rule\MultipleProjectRuleViolationInterface;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Rule\UsedInterfaceAwareRuleInterface;
use Boundwize\StructArmed\Rule\UsedTraitAwareRuleInterface;
use Boundwize\StructArmed\Util\Path;

use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function getcwd;
use function in_array;
use function is_dir;
use function is_file;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function substr;

final readonly class Analyser
{
    private string $basePath;

    private string $normalisedBasePath;

    public function __construct(
        string $basePath = '',
        private ?AnalysisResultCache $analysisResultCache = null,
        private string $classNodeCacheNamespace = '',
        private PhpFileCollector $phpFileCollector = new PhpFileCollector(),
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

        $layers          = $this->resolveLayers($architecture);
        $rules           = $architecture->getRules();
        $globalSkipPaths = $architecture->getSkipPaths();
        $ruleSkipPaths   = $architecture->getRuleSkipPaths();
        $skippedRuleKeys = $this->skippedRuleKeyMap($architecture->getSkippedRuleKeys());

        $projectRuleViolations     = [];
        $fileAnalysisRules         = [];
        $classRules                = [];
        $layerAwareRules           = [];
        $hasExtendedClassAwareRule = false;
        $hasUsedInterfaceAwareRule = false;
        $hasUsedTraitAwareRule     = false;

        foreach ($rules as $key => $rule) {
            if (array_key_exists($key, $skippedRuleKeys)) {
                continue;
            }

            if ($rule instanceof RuleInterface) {
                $classRules[$key] = $rule;
            }

            if ($rule instanceof LayerAwareRuleInterface) {
                $layerAwareRules[] = $rule;
            }

            if ($rule instanceof ExtendedClassAwareRuleInterface) {
                $hasExtendedClassAwareRule = true;
            }

            if ($rule instanceof UsedInterfaceAwareRuleInterface) {
                $hasUsedInterfaceAwareRule = true;
            }

            if ($rule instanceof UsedTraitAwareRuleInterface) {
                $hasUsedTraitAwareRule = true;
            }

            if (! $rule instanceof ProjectRuleInterface) {
                continue;
            }

            $projectRuleViolations[$key] = [];

            if ($rule instanceof FileAnalysisRuleInterface) {
                $fileAnalysisRules[$key] = $rule;
                continue;
            }

            $projectRuleSkipPaths = $this->mergedSkipPaths($globalSkipPaths, $ruleSkipPaths[$key] ?? []);

            if ($rule instanceof MultipleProjectRuleViolationInterface) {
                $projectRuleViolations[$key] = $this->withoutSkippedProjectViolations(
                    $rule->evaluateProjectAll($this->basePath, $architecture, $projectRuleSkipPaths),
                    $projectRuleSkipPaths,
                );
            } else {
                $single     = $rule->evaluateProject($this->basePath, $architecture, $projectRuleSkipPaths);
                $violations = $single instanceof RuleViolation ? [$single] : [];

                $projectRuleViolations[$key] = $this->withoutSkippedProjectViolations(
                    $violations,
                    $projectRuleSkipPaths,
                );
            }
        }

        $ruleSkipMatchers   = $this->ruleSkipMatchers($classRules, $globalSkipPaths, $ruleSkipPaths);
        $layerPatterns      = $architecture->getLayerPatterns();
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig($layers, $this->basePath, $layerPatterns);

        $files          ??= $this->filesForAnalysis($architecture, $scanPaths, $layers);
        $withFileAnalysis = $fileAnalysisRules !== [];
        $extractionResult = $this->collectClassNodes(
            $files,
            $progressHandler,
            $layers,
            $layerPatterns,
            $chainLayerResolver,
            $analyserOptions ?? AnalyserOptions::parallel(),
            $withFileAnalysis,
        );
        $classNodes       = $extractionResult->classNodes;
        $classNodes       = $this->withRecursiveParents($classNodes);

        if ($hasExtendedClassAwareRule || $hasUsedInterfaceAwareRule || $hasUsedTraitAwareRule) {
            $this->markClassLikeUsage(
                $classNodes,
                $extractionResult,
                $hasExtendedClassAwareRule,
                $hasUsedInterfaceAwareRule,
            );
        }

        if ($withFileAnalysis) {
            $fileAnalysisProvider = new FileAnalysisProvider(
                analyses: $extractionResult->fileAnalyses,
                isScopeFilesEnabled: true,
            );

            foreach ($fileAnalysisRules as $key => $rule) {
                $projectRuleViolations[$key] = $rule->evaluateProjectAllWithProvider(
                    $this->basePath,
                    $architecture,
                    $fileAnalysisProvider,
                    $this->mergedSkipPaths($globalSkipPaths, $ruleSkipPaths[$key] ?? []),
                );
            }
        }

        foreach ($projectRuleViolations as $key => $violations) {
            $isFixable = $rules[$key] instanceof FixableInterface;

            foreach ($violations as $violation) {
                $ruleViolationCollection->add(new RuleViolation(
                    message:   $violation->message,
                    file:      $violation->file,
                    line:      $violation->line,
                    className: $violation->className,
                    layer:     $violation->layer,
                    ruleKey:   $key,
                    fixable:   $isFixable,
                    methodName: $violation->methodName,
                    constantName: $violation->constantName,
                    propertyName: $violation->propertyName,
                ));
            }
        }

        // Evaluate declarative ruleset alongside class rules, but buffer its
        // violations so report ordering remains class rules before ruleset.
        $ruleset = $this->expandRuleset($architecture->getRuleset());

        // Precompute hash maps once so the per-dependency hot loop below uses
        // O(1) isset() lookups instead of in_array()/array_intersect() scans.
        $rulesetAllowedLayerMaps = [];

        foreach ($ruleset as $rulesetLayer => $allowedLayers) {
            $rulesetAllowedLayerMaps[$rulesetLayer] = array_fill_keys($allowedLayers, true);
        }

        $classViolationSkipMaps = [];

        foreach ($architecture->getClassViolationSkips() as $skipClassName => $skippedDependencies) {
            $classViolationSkipMaps[$skipClassName] = array_fill_keys($skippedDependencies, true);
        }

        $rulesetSkipPaths           = $this->mergedSkipPaths($globalSkipPaths, $architecture->getRulesetSkipPaths());
        $rulesetSkipPathMatcher     = SkipPathMatcher::compile($this->basePath, $rulesetSkipPaths);
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
        $classLayerMap            = $classDependencyMaps['classLayerMap'];
        $classPrimaryLayerMap     = $classDependencyMaps['classPrimaryLayerMap'];

        $resolvedInheritedDependencies = [];

        foreach ($layerAwareRules as $rule) {
            $rule->injectClassNodeMap($classDependencyMaps['classNodeMap']);
        }

        foreach ($classNodes as $classNode) {
            foreach ($classRules as $key => $rule) {
                if ($ruleSkipMatchers[$key]->isSkipped($classNode->file)) {
                    continue;
                }

                if (! $rule->appliesTo($classNode)) {
                    continue;
                }

                if ($rule instanceof MultipleRuleViolationInterface) {
                    $violations = $rule->evaluateAll($classNode);
                } else {
                    $violation = $rule->evaluate($classNode);
                    if (! $violation instanceof RuleViolation) {
                        continue;
                    }

                    $violations = [$violation];
                }

                $isFixable = $rule instanceof FixableInterface;

                foreach ($violations as $violation) {
                    // Inject the rule key into the violation
                    $ruleViolationCollection->add(new RuleViolation(
                        message:   $violation->message,
                        file:      $violation->file,
                        line:      $violation->line,
                        className: $violation->className,
                        layer:     $violation->layer,
                        ruleKey:   $key,
                        fixable:   $isFixable,
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

            if ($rulesetSkipPaths !== [] && $rulesetSkipPathMatcher->isSkipped($classNode->file)) {
                continue;
            }

            $allowedLayerMap = $rulesetAllowedLayerMaps[$classNode->layer] ?? null;

            if ($allowedLayerMap === null) {
                // Layer not listed in ruleset — no restriction.
                continue;
            }

            $skippedDepsForClass = $classViolationSkipMaps[$classNode->className] ?? [];
            $dependencies        = $this->dependenciesForClass(
                $classNode->className,
                $dependencyMap,
                $inheritanceDependencyMap,
                $resolvedInheritedDependencies
            );

            foreach ($dependencies as $dependency) {
                if (isset($skippedDepsForClass[$dependency])) {
                    continue;
                }

                $primaryLayer = $classPrimaryLayerMap[$dependency] ?? null;

                if ($primaryLayer !== null && ! array_key_exists($primaryLayer, $scanScopeLayerMap)) {
                    // Scanned dep in a specific layer (not a PSR4 catch-all): keep every
                    // layer collected at scan time, including path-based ones.
                    $depLayers = $classLayerMap[$dependency] ?? [$primaryLayer];
                } else {
                    // Unscanned or catch-all dep: only class-name regex layers can match.
                    $depLayers = $chainLayerResolver->resolveAll($dependency, '');
                }

                if ($depLayers === []) {
                    // External / unregistered dependency — not restricted.
                    continue;
                }

                // Same-layer dependencies are always allowed, whether the shared
                // layer is the dependency's primary layer or a secondary one.
                // The explicit primary-layer check is not redundant: for a dep
                // whose primary layer is a PSR-4 catch-all, $depLayers is
                // regex-resolved only and need not contain the primary layer.
                $isSameLayer = $primaryLayer === $classNode->layer
                    || in_array($classNode->layer, $depLayers, true);

                if ($isSameLayer) {
                    continue;
                }

                // A dependency is permitted when any of its layers is explicitly allowed,
                // regardless of whether the dependency was scanned or regex-resolved.
                foreach ($depLayers as $depLayer) {
                    if (isset($allowedLayerMap[$depLayer])) {
                        continue 2;
                    }
                }

                $violatingLayer = $primaryLayer ?? $depLayers[0];

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
     * @param array<string, RuleInterface> $classRules
     * @param list<string>                 $globalSkipPaths
     * @param array<string, list<string>>  $ruleSkipPaths
     * @return array<string, SkipPathMatcher>
     */
    private function ruleSkipMatchers(array $classRules, array $globalSkipPaths, array $ruleSkipPaths): array
    {
        $ruleSkipMatchers = [];

        foreach (array_keys($classRules) as $key) {
            $ruleSkipMatchers[$key] = SkipPathMatcher::compile(
                $this->basePath,
                $this->mergedSkipPaths($globalSkipPaths, $ruleSkipPaths[$key] ?? [])
            );
        }

        return $ruleSkipMatchers;
    }

    /**
     * @param list<string> $globalSkipPaths
     * @param list<string> $ruleSkipPaths
     * @return list<string>
     */
    private function mergedSkipPaths(array $globalSkipPaths, array $ruleSkipPaths): array
    {
        if ($globalSkipPaths === []) {
            return $ruleSkipPaths;
        }

        if ($ruleSkipPaths === []) {
            return $globalSkipPaths;
        }

        return array_values(array_unique([...$globalSkipPaths, ...$ruleSkipPaths]));
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
        // Associative set keeps first-occurrence order and dedupes on insert,
        // avoiding a list-then-array_unique() pass over the accumulated closure.
        $dependencies = [];

        foreach ($dependencyMap[$className] ?? [] as $dependency) {
            $dependencies[$dependency] = true;
        }

        foreach ($inheritanceDependencyMap[$className] ?? [] as $dependency) {
            $cycleDetected = false;
            $resolved      = $this->dependenciesForInheritanceDependency(
                $dependency,
                $dependencyMap,
                $inheritanceDependencyMap,
                $resolvedInheritedDependencies,
                [$className => true],
                $cycleDetected
            );

            foreach ($resolved as $resolvedDependency) {
                $dependencies[$resolvedDependency] = true;
            }
        }

        return array_keys($dependencies);
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
        if (isset($seen[$dependency])) {
            $cycleDetected = true;

            return [$dependency];
        }

        if (isset($resolvedInheritedDependencies[$dependency])) {
            return $resolvedInheritedDependencies[$dependency];
        }

        $resolvedDependencies = [$dependency => true];
        $hasCycle             = false;

        foreach ($dependencyMap[$dependency] ?? [] as $mappedDependency) {
            $resolvedDependencies[$mappedDependency] = true;
        }

        $seen += [$dependency => true];

        foreach ($inheritanceDependencyMap[$dependency] ?? [] as $inheritedDependency) {
            $childHasCycle = false;
            $resolved      = $this->dependenciesForInheritanceDependency(
                $inheritedDependency,
                $dependencyMap,
                $inheritanceDependencyMap,
                $resolvedInheritedDependencies,
                $seen,
                $childHasCycle
            );

            foreach ($resolved as $resolvedDependency) {
                $resolvedDependencies[$resolvedDependency] = true;
            }

            $hasCycle = $hasCycle || $childHasCycle;
        }

        $resolvedDependencies = array_keys($resolvedDependencies);

        if (! $hasCycle) {
            $resolvedInheritedDependencies[$dependency] = $resolvedDependencies;
        }

        $cycleDetected = $cycleDetected || $hasCycle;

        return $resolvedDependencies;
    }

    /**
     * Collect and apply class-like usage flags with one collection pass and one
     * application pass over the class nodes. Extended classes use the recursive
     * parent chain resolved by {@see withRecursiveParents()}; instantiated
     * classes come from resolved `new` expressions collected per file.
     *
     * @param list<ClassNode> $classNodes
     */
    private function markClassLikeUsage(
        array $classNodes,
        ExtractionResult $extractionResult,
        bool $markExtended,
        bool $markImplemented,
    ): void {
        $extended     = [];
        $implemented  = [];
        $used         = [];
        $instantiated = [];

        foreach ($classNodes as $classNode) {
            if ($markExtended) {
                foreach ($classNode->parentClasses as $parentClass) {
                    $extended[strtolower($parentClass)] = true;
                }
            }

            if ($markImplemented) {
                foreach ($classNode->parentInterfaces as $parentInterface) {
                    $implemented[strtolower($parentInterface)] = true;
                }
            }

            foreach ($classNode->traits as $trait) {
                $used[strtolower($trait)] = true;
            }

            // A node's own inheritance-clause names (and the imports that
            // exist for them) are structural relations, not value references.
            // Excluding them keeps "referenced" meaningful for the unresolved
            // dynamic instantiation check below: a class extended by a child
            // is not thereby a possible `new $class` target. The usage-aware
            // deletion rules are unaffected — each combines this flag with its
            // structural extended/implemented/trait marking.
            $excludedKeys = [strtolower($classNode->className) => true];

            if ($classNode->extends !== null) {
                $excludedKeys[strtolower($classNode->extends)] = true;
            }

            foreach ([$classNode->implements, $classNode->interfaceExtends, $classNode->traits] as $clauseNames) {
                foreach ($clauseNames as $clauseName) {
                    $excludedKeys[strtolower($clauseName)] = true;
                }
            }

            foreach ($classNode->dependencies as $dependency) {
                $dependencyKey = strtolower($dependency);

                if (! isset($excludedKeys[$dependencyKey])) {
                    $used[$dependencyKey] = true;
                }
            }
        }

        // Anonymous classes have no ClassNode of their own, so their inheritance
        // and trait-use relationships are tracked separately.
        foreach ($extractionResult->anonymousClassNodes as $anonymousClassNode) {
            if ($markExtended && $anonymousClassNode->extends !== null) {
                $extended[strtolower($anonymousClassNode->extends)] = true;
            }

            if ($markImplemented) {
                foreach ($anonymousClassNode->implements as $interface) {
                    $implemented[strtolower($interface)] = true;
                }
            }

            foreach ($anonymousClassNode->traits as $trait) {
                $used[strtolower($trait)] = true;
            }
        }

        // References made outside any named class-like scope — procedural
        // functions, top-level statements, top-level anonymous class bodies —
        // have no ClassNode either, so they are tracked per file.
        foreach ($extractionResult->fileReferences as $references) {
            foreach ($references as $reference) {
                $used[strtolower($reference)] = true;
            }
        }

        foreach ($extractionResult->fileInstantiations as $classNames) {
            foreach ($classNames as $className) {
                $instantiated[strtolower($className)] = true;
            }
        }

        foreach ($classNodes as $classNode) {
            $classNameKey = strtolower($classNode->className);

            if ($markExtended && isset($extended[$classNameKey])) {
                $classNode->setExtended(true);
            }

            if ($markExtended && isset($instantiated[$classNameKey])) {
                $classNode->setInstantiated(true);
            }

            if ($markImplemented && isset($implemented[$classNameKey])) {
                $classNode->setImplemented(true);
            }

            // An instantiation is also a reference, so reuse its lookup here.
            if (isset($instantiated[$classNameKey]) || isset($used[$classNameKey])) {
                $classNode->setReferenced(true);
            }
        }
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
            $classNameKey                      = strtolower($classNode->className);
            $parentClassMap[$classNameKey]     = $classNode->extends !== null
                ? [$classNode->extends]
                : [];
            $parentInterfaceMap[$classNameKey] = $classNode->interfaceExtends !== []
                ? array_values(array_unique([...$classNode->implements, ...$classNode->interfaceExtends]))
                : array_values($classNode->implements);
        }

        foreach ($classNodes as $classNode) {
            $classNameKey = strtolower($classNode->className);

            if (
                $parentClassMap[$classNameKey] === []
                && $parentInterfaceMap[$classNameKey] === []
            ) {
                continue;
            }

            $cycleDetected = false;
            $result        = $this->recursiveParents(
                $classNameKey,
                $parentClassMap,
                $parentInterfaceMap,
                $parentsCache,
                [$classNameKey => true],
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
        string $classNameKey,
        array $parentClassMap,
        array $parentInterfaceMap,
        array &$cache,
        array $seen,
        bool &$cycleDetected
    ): array {
        if (isset($cache[$classNameKey])) {
            return $cache[$classNameKey];
        }

        $classesSet    = [];
        $interfacesSet = [];
        $hasCycle      = false;

        foreach ($parentClassMap[$classNameKey] ?? [] as $parentClass) {
            $parentClassKey = strtolower($parentClass);

            if (isset($seen[$parentClassKey])) {
                $hasCycle = true;
                continue;
            }

            $childHasCycle            = false;
            $classesSet[$parentClass] = true;
            $result                   = $this->recursiveParents(
                $parentClassKey,
                $parentClassMap,
                $parentInterfaceMap,
                $cache,
                $seen + [$parentClassKey => true],
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

        foreach ($parentInterfaceMap[$classNameKey] ?? [] as $parentInterface) {
            $parentInterfaceKey = strtolower($parentInterface);

            if (isset($seen[$parentInterfaceKey])) {
                $hasCycle = true;
                continue;
            }

            $childHasCycle                   = false;
            $interfacesSet[$parentInterface] = true;
            $result                          = $this->recursiveParents(
                $parentInterfaceKey,
                $parentClassMap,
                $parentInterfaceMap,
                $cache,
                $seen + [$parentInterfaceKey => true],
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
            $cache[$classNameKey] = $result;
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
        $classNodes          = [];
        $fileAnalyses        = [];
        $anonymousClassNodes = [];
        $fileReferences      = [];
        $fileInstantiations  = [];
        $filesToParse        = [];

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

                foreach ($cachedResult['anonymousClassNodes'] as $cachedAnonymousClassNode) {
                    $anonymousClassNodes[] = $cachedAnonymousClassNode;
                }

                if ($cachedResult['fileReferences'] !== []) {
                    $fileReferences[$file] = $cachedResult['fileReferences'];
                }

                if ($cachedResult['fileInstantiations'] !== []) {
                    $fileInstantiations[$file] = $cachedResult['fileInstantiations'];
                }

                $fileAnalyses[$file] = $cachedResult['fileAnalysis'];

                continue;
            }

            $cachedResult = $this->analysisResultCache?->loadClassNodes(
                $file,
                $this->classNodeCacheNamespace,
            );

            if ($cachedResult === null) {
                $filesToParse[] = $file;
                continue;
            }

            foreach ($cachedResult['classNodes'] as $cachedClassNode) {
                $classNodes[] = $cachedClassNode;
            }

            foreach ($cachedResult['anonymousClassNodes'] as $cachedAnonymousClassNode) {
                $anonymousClassNodes[] = $cachedAnonymousClassNode;
            }

            if ($cachedResult['fileReferences'] !== []) {
                $fileReferences[$file] = $cachedResult['fileReferences'];
            }

            if ($cachedResult['fileInstantiations'] !== []) {
                $fileInstantiations[$file] = $cachedResult['fileInstantiations'];
            }
        }

        $progressHandler?->start(count($filesToParse));

        if ($filesToParse === []) {
            $progressHandler?->finish();

            return new ExtractionResult(
                $classNodes,
                $fileAnalyses,
                $anonymousClassNodes,
                $fileReferences,
                $fileInstantiations,
            );
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

        $anonymousClassNodesByFile = array_fill_keys($filesToParse, []);
        foreach ($parsedResult->anonymousClassNodes as $parsedAnonymousClassNode) {
            $anonymousClassNodes[] = $parsedAnonymousClassNode;

            if (isset($anonymousClassNodesByFile[$parsedAnonymousClassNode->file])) {
                $anonymousClassNodesByFile[$parsedAnonymousClassNode->file][] = $parsedAnonymousClassNode;
            }
        }

        foreach ($parsedResult->fileAnalyses as $file => $fileAnalysis) {
            $fileAnalyses[$file] = $fileAnalysis;
        }

        foreach ($parsedResult->fileReferences as $file => $parsedFileReferences) {
            $fileReferences[$file] = $parsedFileReferences;
        }

        foreach ($parsedResult->fileInstantiations as $file => $parsedFileInstantiations) {
            $fileInstantiations[$file] = $parsedFileInstantiations;
        }

        foreach ($classNodesByFile as $fileToParse => $fileClassNodes) {
            $this->analysisResultCache?->storeClassNodes(
                $fileToParse,
                $this->classNodeCacheNamespace,
                $fileClassNodes,
                $fileAnalyses[$fileToParse] ?? null,
                $anonymousClassNodesByFile[$fileToParse] ?? [],
                $fileReferences[$fileToParse] ?? [],
                $fileInstantiations[$fileToParse] ?? [],
            );
        }

        $progressHandler?->finish();

        return new ExtractionResult(
            $classNodes,
            $fileAnalyses,
            $anonymousClassNodes,
            $fileReferences,
            $fileInstantiations,
        );
    }

    /**
     * @param list<RuleViolation> $violations
     * @param list<string> $skipPaths
     * @return list<RuleViolation>
     */
    private function withoutSkippedProjectViolations(array $violations, array $skipPaths): array
    {
        if ($violations === [] || $skipPaths === []) {
            return $violations;
        }

        $skipPathMatcher = SkipPathMatcher::compile($this->basePath, $skipPaths);

        return array_values(array_filter(
            $violations,
            static fn(RuleViolation $ruleViolation): bool => $ruleViolation->file === ''
                || ! $skipPathMatcher->isSkipped($ruleViolation->file),
        ));
    }

    /**
     * @param list<string> $scanPaths
     * @param array<string, string|list<string>>|null $layers
     * @return list<string>
     */
    public function filesForAnalysis(Architecture $architecture, array $scanPaths = [], ?array $layers = null): array
    {
        $layers        ??= $this->resolveLayers($architecture);
        $files           = [];
        $skipPathMatcher = SkipPathMatcher::compile($this->basePath, $architecture->getSkipPaths());
        $scanPaths       = $this->scanPaths($layers, $scanPaths);

        if ($this->shouldAnalyseComposerJson($architecture)) {
            $scanPaths[] = 'composer.json';
        }

        foreach (array_values(array_unique($scanPaths)) as $layerPath) {
            $fullPath = Path::normalise(
                Path::resolve($layerPath, $this->basePath),
                canonicalise: true
            );

            if ($skipPathMatcher->isSkipped($fullPath)) {
                continue;
            }

            if (is_file($fullPath)) {
                if (Path::isAnalysableFile($fullPath, $this->basePath)) {
                    $files[] = $fullPath;
                }

                continue;
            }

            if (! is_dir($fullPath)) {
                continue;
            }

            foreach ($this->phpFileCollector->collect($fullPath, $skipPathMatcher) as $file) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    private function shouldAnalyseComposerJson(Architecture $architecture): bool
    {
        $skippedRuleKeys = $this->skippedRuleKeyMap($architecture->getSkippedRuleKeys());
        $globalSkipPaths = $architecture->getSkipPaths();
        $ruleSkipPaths   = $architecture->getRuleSkipPaths();

        foreach ($architecture->getRules() as $key => $rule) {
            if (array_key_exists($key, $skippedRuleKeys)) {
                continue;
            }

            if (! $rule instanceof ComposerJsonRuleInterface) {
                continue;
            }

            if (
                $this->isComposerJsonSkippedByRule(
                    $this->mergedSkipPaths($globalSkipPaths, $ruleSkipPaths[$key] ?? [])
                )
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param list<string> $skipPaths
     */
    private function isComposerJsonSkippedByRule(array $skipPaths): bool
    {
        if ($skipPaths === []) {
            return false;
        }

        return SkipPathMatcher::compile($this->basePath, $skipPaths)->isSkipped(
            Path::resolve('composer.json', $this->normalisedBasePath)
        );
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function resolveLayers(Architecture $architecture): array
    {
        $layers           = $architecture->getLayers();
        $sourcePaths      = $layers['Source'] ?? null;
        $sourceArrayPaths = $layers['Source[]'] ?? null;

        if ($sourcePaths === []) {
            $sourcePaths      = (new Psr4PathResolver())->paths($this->basePath);
            $layers['Source'] = $sourcePaths;
        }

        if ($sourceArrayPaths === [] && $sourcePaths !== null && $sourcePaths !== []) {
            $layers['Source[]'] = $sourcePaths;
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
            return $scanPaths;
        }

        $paths = [];

        foreach ($layers as $layer) {
            foreach ((array) $layer as $layerPath) {
                $paths[] = $layerPath;
            }
        }

        return $paths;
    }
}
