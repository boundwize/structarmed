<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use Boundwize\StructArmed\Analyser\ClassNodeExtractor;
use Boundwize\StructArmed\Analyser\Parallel\ParallelClassNodeExtractor;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Cache\AnalysisResultCache;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;
use Boundwize\StructArmed\Progress\ProgressHandlerInterface;
use Boundwize\StructArmed\Rule\MultipleProjectRuleViolationInterface;
use Boundwize\StructArmed\Rule\MultipleRuleViolationInterface;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleInterface;
use Boundwize\StructArmed\Rule\Rules\Composer\Psr4SourcePathsRule;
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
use function array_unique;
use function array_values;
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
        $this->normalisedBasePath = $this->normalisePath($this->basePath);
    }

    /**
     * @param list<string> $scanPaths
     */
    public function analyse(
        Architecture $architecture,
        array $scanPaths = [],
        ?ProgressHandlerInterface $progressHandler = null,
        ?AnalyserOptions $analyserOptions = null
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

        $files      = $this->filesForAnalysis($architecture, $scanPaths);
        $classNodes = $this->collectClassNodes(
            $files,
            $progressHandler,
            $layers,
            $layerPatterns,
            $analyserOptions ?? AnalyserOptions::parallel()
        );

        // Evaluate declarative ruleset alongside class rules, but buffer its
        // violations so report ordering remains class rules before ruleset.
        $ruleset                    = $architecture->getRuleset();
        $classViolationSkips        = $architecture->getClassViolationSkips();
        $rulesetSkipPaths           = $architecture->getRulesetSkipPaths();
        $rulesetViolationCollection = new RuleViolationCollection();
        $hasRuleset                 = $ruleset !== [];
        $classDependencyMaps        = $hasRuleset
            ? $this->classDependencyMaps($classNodes)
            : [
                'dependencies'            => [],
                'inheritanceDependencies' => [],
            ];
        $dependencyMap              = $classDependencyMaps['dependencies'];
        $inheritanceDependencyMap   = $classDependencyMaps['inheritanceDependencies'];

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
     *     inheritanceDependencies: array<string, list<string>>
     * }
     */
    private function classDependencyMaps(array $classNodes): array
    {
        $dependencyMap            = [];
        $inheritanceDependencyMap = [];

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
        }

        return [
            'dependencies'            => $dependencyMap,
            'inheritanceDependencies' => $inheritanceDependencyMap,
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
     * @param array<string, array{pattern: string, excludePattern: string|null}> $layerPatterns
     * @return list<ClassNode>
     */
    private function collectClassNodes(
        array $files,
        ?ProgressHandlerInterface $progressHandler,
        array $layers,
        array $layerPatterns,
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
            $layerResolver    = ChainLayerResolver::fromLayerConfig($layers, $this->basePath, $layerPatterns);
            $parsedClassNodes = (new ClassNodeExtractor($layerResolver))->extract($filesToParse, $progressHandler);
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
        if ($skipPaths === []) {
            return false;
        }

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
        $basePath = $this->normalisedBasePath;

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
                    if (! $file->isDir() && $file->getExtension() !== 'php') {
                        return false;
                    }

                    return ! $this->isSkipped($file->getPathname(), $skipPaths);
                }
            )
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $realPath = $file->getRealPath();

            if ($realPath !== false) {
                $files[] = $realPath;
            }
        }

        return $files;
    }
}
