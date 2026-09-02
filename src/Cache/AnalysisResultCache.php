<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use Boundwize\StructArmed\Analyser\AnonymousClassNode;
use Boundwize\StructArmed\Analyser\AnonymousFunctionNode;
use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ConstantNode;
use Boundwize\StructArmed\Analyser\EnumCaseNode;
use Boundwize\StructArmed\Analyser\ExtractionResult;
use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FunctionNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Analyser\PropertyNode;
use Boundwize\StructArmed\Composer\ComposerJsonProvider;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;

use function array_fill_keys;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function is_array;
use function is_bool;
use function is_dir;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function rmdir;
use function rtrim;
use function sprintf;
use function unlink;

use const GLOB_NOSORT;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * @internal
 */
final class AnalysisResultCache
{
    /**
     * Marker file recording the cache format version and the config and
     * structarmed version hashes the cache contents were built with. Never
     * collides with payload files: those are named by hex hash keys or an
     * "analysis-nodes-" prefix.
     */
    private const METADATA_FILE = '_metadata.json';

    /**
     * Format version of the analysis-node payload files. Bump it whenever
     * their shape or naming changes: it is recorded in the metadata marker,
     * so a cache written by an older format is cleared on its next use.
     */
    public const FORMAT_VERSION = 2;

    private readonly string $cacheDirectory;

    /** Hash of the project composer.json: its PSR-4 mappings decide layer assignments. */
    private readonly string $composerHash;

    private bool $isCacheInitialised = false;

    public function __construct(
        string $basePath,
        private FileHashProvider $fileHashProvider,
        ?string $cacheDirectory = null,
        private readonly string $configHash = '',
        private readonly string $composerGeneratedVersionHash = '',
        private readonly ComposerJsonProvider $composerJsonProvider = new ComposerJsonProvider(),
    ) {
        $this->cacheDirectory = CachePathFactory::getPath($cacheDirectory, $basePath);
        $composerFile         = rtrim($basePath, '/') . '/composer.json';
        $this->composerHash   = file_exists($composerFile) ? $fileHashProvider->hash($composerFile) : '';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function load(string $key, array $metadata): ?RuleViolationCollection
    {
        $payload = $this->read($key);

        if ($payload === null) {
            return null;
        }

        if (($payload['metadata'] ?? null) !== $metadata) {
            return null;
        }

        if (! is_array($payload['violations'] ?? null)) {
            return null;
        }

        $ruleViolationCollection = new RuleViolationCollection();

        foreach ($payload['violations'] as $violation) {
            if (! is_array($violation)) {
                return null;
            }

            $ruleViolation = $this->ruleViolationFromArray($violation);

            if (! $ruleViolation instanceof RuleViolation) {
                return null;
            }

            $ruleViolationCollection->add($ruleViolation);
        }

        return $ruleViolationCollection;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function store(string $key, array $metadata, RuleViolationCollection $ruleViolationCollection): void
    {
        $this->ensureCacheInitialised();

        file_put_contents($this->path($key), json_encode([
            'metadata'   => $metadata,
            'violations' => $ruleViolationCollection->toArray(),
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    public function clear(): void
    {
        $this->isCacheInitialised = false;
        $this->fileHashProvider->clear();
        $this->composerJsonProvider->clear();

        if (! is_dir($this->cacheDirectory)) {
            return;
        }

        foreach (glob($this->cacheDirectory . '/*', GLOB_NOSORT) ?: [] as $path) {
            if (is_dir($path)) {
                rmdir($path);
                continue;
            }

            unlink($path);
        }

        rmdir($this->cacheDirectory);
    }

    public function getCacheDirectory(): string
    {
        return $this->cacheDirectory;
    }

    /**
     * @param list<string> $files
     */
    public function forFiles(array $files): self
    {
        $cache                   = clone $this;
        $cache->fileHashProvider = $this->fileHashProvider->forFiles($files);

        return $cache;
    }

    /**
     * Compares against the single metadata marker instead of scanning every
     * payload, so the check stays O(1) regardless of cache size. A populated
     * cache without a marker, or with a marker from an older cache format
     * version, must be invalidated.
     */
    public function shouldInvalidate(): bool
    {
        if (! is_dir($this->cacheDirectory)) {
            return false;
        }

        $payload = $this->readPath($this->cacheDirectory . '/' . self::METADATA_FILE);

        return ($payload['version'] ?? null) !== self::FORMAT_VERSION
            || ($payload['configHash'] ?? null) !== $this->configHash
            || ($payload['composerGeneratedVersionHash'] ?? null) !== $this->composerGeneratedVersionHash
            || ($payload['composerHash'] ?? null) !== $this->composerHash;
    }

    private function ensureCacheInitialised(): void
    {
        if ($this->isCacheInitialised) {
            return;
        }

        if (! is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0777, true);
        }

        $metadataFile = $this->cacheDirectory . '/' . self::METADATA_FILE;

        if (! file_exists($metadataFile)) {
            file_put_contents($metadataFile, json_encode([
                'version'                      => self::FORMAT_VERSION,
                'configHash'                   => $this->configHash,
                'composerGeneratedVersionHash' => $this->composerGeneratedVersionHash,
                'composerHash'                 => $this->composerHash,
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        }

        $this->isCacheInitialised = true;
    }

    /**
     * @return array{
     *     classNodes: list<ClassNode>,
     *     anonymousClassNodes: list<AnonymousClassNode>,
     *     fileReferences: list<string>,
     *     fileInstantiations: list<string>,
     *     functionNodes: list<FunctionNode>,
     *     anonymousFunctionNodes: list<AnonymousFunctionNode>
     * }|null
     */
    public function loadAnalysisNodes(string $file, string $namespace): ?array
    {
        $payload = $this->analysisNodePayload($file, $namespace);

        if ($payload === null) {
            return null;
        }

        return $this->analysisNodeResultFromPayload($payload, $file);
    }

    /**
     * @return array{
     *     classNodes: list<ClassNode>,
     *     anonymousClassNodes: list<AnonymousClassNode>,
     *     fileReferences: list<string>,
     *     fileInstantiations: list<string>,
     *     functionNodes: list<FunctionNode>,
     *     anonymousFunctionNodes: list<AnonymousFunctionNode>,
     *     fileAnalysis: FileAnalysis
     * }|null
     */
    public function loadAnalysisNodesWithFileAnalysis(string $file, string $namespace): ?array
    {
        $payload = $this->analysisNodePayload($file, $namespace);

        if ($payload === null) {
            return null;
        }

        $fileAnalysis = is_array($payload['fileAnalysis'] ?? null)
            ? $this->fileAnalysisFromArray($payload['fileAnalysis'])
            : null;

        if (! $fileAnalysis instanceof FileAnalysis) {
            return null;
        }

        $result = $this->analysisNodeResultFromPayload($payload, $file);

        if ($result === null) {
            return null;
        }

        $result['fileAnalysis'] = $fileAnalysis;

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     classNodes: list<ClassNode>,
     *     anonymousClassNodes: list<AnonymousClassNode>,
     *     fileReferences: list<string>,
     *     fileInstantiations: list<string>,
     *     functionNodes: list<FunctionNode>,
     *     anonymousFunctionNodes: list<AnonymousFunctionNode>
     * }|null
     */
    private function analysisNodeResultFromPayload(array $payload, string $file): ?array
    {
        $classNodes             = $this->classNodesFromPayload($payload);
        $anonymousClassNodes    = $this->anonymousClassNodesFromPayload($payload);
        $fileReferences         = $this->fileReferencesFromPayload($payload);
        $fileInstantiations     = $this->fileInstantiationsFromPayload($payload);
        $functionNodes          = $this->functionNodesFromPayload($payload, $file);
        $anonymousFunctionNodes = $this->anonymousFunctionNodesFromPayload($payload, $file);

        if (
            $classNodes === null
            || $anonymousClassNodes === null
            || $fileReferences === null
            || $fileInstantiations === null
            || $functionNodes === null
            || $anonymousFunctionNodes === null
        ) {
            return null;
        }

        return [
            'classNodes'             => $classNodes,
            'anonymousClassNodes'    => $anonymousClassNodes,
            'fileReferences'         => $fileReferences,
            'fileInstantiations'     => $fileInstantiations,
            'functionNodes'          => $functionNodes,
            'anonymousFunctionNodes' => $anonymousFunctionNodes,
        ];
    }

    /** @return array<string, mixed>|null */
    private function analysisNodePayload(string $file, string $namespace): ?array
    {
        $payload = $this->read($this->analysisNodesKey($file, $namespace));

        if ($payload === null || ($payload['metadata'] ?? null) !== $this->fileMetadata($file, $namespace)) {
            return null;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<ClassNode>|null
     */
    private function classNodesFromPayload(array $payload): ?array
    {
        if (! is_array($payload['nodes'] ?? null)) {
            return null;
        }

        $nodes = [];

        foreach ($payload['nodes'] as $node) {
            if (! is_array($node)) {
                return null;
            }

            $classNode = $this->classNodeFromArray($node);

            if (! $classNode instanceof ClassNode) {
                return null;
            }

            $nodes[] = $classNode;
        }

        return $nodes;
    }

    /**
     * Stores the parsed nodes of every file in $files from one extraction result,
     * one payload per file (files without nodes get an empty payload too, so they
     * are cache hits next run).
     *
     * @param list<string> $files
     */
    public function storeExtractionResult(array $files, string $namespace, ExtractionResult $extractionResult): void
    {
        $classNodesByFile             = array_fill_keys($files, []);
        $anonymousClassNodesByFile    = $classNodesByFile;
        $functionNodesByFile          = $classNodesByFile;
        $anonymousFunctionNodesByFile = $classNodesByFile;

        foreach ($extractionResult->classNodes as $classNode) {
            if (isset($classNodesByFile[$classNode->file])) {
                $classNodesByFile[$classNode->file][] = $classNode;
            }
        }

        foreach ($extractionResult->anonymousClassNodes as $anonymousClassNode) {
            if (isset($anonymousClassNodesByFile[$anonymousClassNode->file])) {
                $anonymousClassNodesByFile[$anonymousClassNode->file][] = $anonymousClassNode;
            }
        }

        foreach ($extractionResult->functionNodes as $functionNode) {
            if (isset($functionNodesByFile[$functionNode->file])) {
                $functionNodesByFile[$functionNode->file][] = $functionNode;
            }
        }

        foreach ($extractionResult->anonymousFunctionNodes as $anonymousFunctionNode) {
            if (isset($anonymousFunctionNodesByFile[$anonymousFunctionNode->file])) {
                $anonymousFunctionNodesByFile[$anonymousFunctionNode->file][] = $anonymousFunctionNode;
            }
        }

        foreach ($files as $file) {
            $this->storeAnalysisNodes(
                $file,
                $namespace,
                $classNodesByFile[$file],
                $extractionResult->fileAnalyses[$file] ?? null,
                $anonymousClassNodesByFile[$file],
                $extractionResult->fileReferences[$file] ?? [],
                $extractionResult->fileInstantiations[$file] ?? [],
                $functionNodesByFile[$file],
                $anonymousFunctionNodesByFile[$file],
            );
        }
    }

    /**
     * @param list<ClassNode>          $classNodes
     * @param list<AnonymousClassNode> $anonymousClassNodes
     * @param list<string>             $fileReferences Class-like references made outside any
     *                                                 named class-like scope in this file
     * @param list<string>             $fileInstantiations Class-like instantiations in this file
     * @param list<FunctionNode>          $functionNodes
     * @param list<AnonymousFunctionNode> $anonymousFunctionNodes
     */
    public function storeAnalysisNodes(
        string $file,
        string $namespace,
        array $classNodes,
        ?FileAnalysis $fileAnalysis = null,
        array $anonymousClassNodes = [],
        array $fileReferences = [],
        array $fileInstantiations = [],
        array $functionNodes = [],
        array $anonymousFunctionNodes = [],
    ): void {
        $this->ensureCacheInitialised();

        $payload = [
            'metadata'            => $this->fileMetadata($file, $namespace),
            'nodes'               => array_map($this->classNodeToArray(...), $classNodes),
            'anonymousClassNodes' => array_map($this->anonymousClassNodeToArray(...), $anonymousClassNodes),
            'fileReferences'      => $fileReferences,
            'fileInstantiations'  => $fileInstantiations,
        ];

        // Most files declare no function-likes; leave their keys out entirely.
        if ($functionNodes !== []) {
            $payload['functionNodes'] = array_map($this->functionNodeToArray(...), $functionNodes);
        }

        if ($anonymousFunctionNodes !== []) {
            $payload['anonymousFunctionNodes'] = array_map(
                $this->anonymousFunctionNodeToArray(...),
                $anonymousFunctionNodes
            );
        }

        if ($fileAnalysis instanceof FileAnalysis) {
            $payload['fileAnalysis'] = $this->fileAnalysisToArray($fileAnalysis);
        }

        file_put_contents(
            $this->path($this->analysisNodesKey($file, $namespace)),
            json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $key): ?array
    {
        return $this->readPath($this->path($key));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPath(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) && $this->hasOnlyStringKeys($payload) ? $payload : null;
    }

    /**
     * @param array<mixed, mixed> $violation
     */
    private function ruleViolationFromArray(array $violation): ?RuleViolation
    {
        $ruleKey   = $violation['rule'] ?? null;
        $message   = $violation['message'] ?? null;
        $file      = $violation['file'] ?? null;
        $line      = $violation['line'] ?? null;
        $className = $violation['class'] ?? null;
        $layer     = $violation['layer'] ?? null;
        $method    = $violation['method'] ?? null;
        $constant  = $violation['constant'] ?? null;
        $property  = $violation['property'] ?? null;
        $function  = $violation['function'] ?? null;
        $fixable   = $violation['fixable'] ?? false;

        if (
            ! is_string($ruleKey)
            || ! is_string($message)
            || ! is_string($file)
            || ! is_int($line)
            || ! is_string($className)
            || ($layer !== null && ! is_string($layer))
            || ($method !== null && ! is_string($method))
            || ($constant !== null && ! is_string($constant))
            || ($property !== null && ! is_string($property))
            || ($function !== null && ! is_string($function))
            || ! is_bool($fixable)
        ) {
            return null;
        }

        return new RuleViolation(
            message:   $message,
            file:      $file,
            line:      $line,
            className: $className,
            layer:     $layer,
            ruleKey:   $ruleKey,
            fixable:   $fixable,
            methodName: $method,
            constantName: $constant,
            propertyName: $property,
            functionName: $function,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>|null
     */
    private function fileReferencesFromPayload(array $payload): ?array
    {
        $fileReferences = $payload['fileReferences'] ?? [];

        return $this->isStringArray($fileReferences) ? array_values($fileReferences) : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>|null
     */
    private function fileInstantiationsFromPayload(array $payload): ?array
    {
        $fileInstantiations = $payload['fileInstantiations'] ?? [];

        return $this->isStringArray($fileInstantiations) ? array_values($fileInstantiations) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function anonymousClassNodeToArray(AnonymousClassNode $anonymousClassNode): array
    {
        return [
            'file'       => $anonymousClassNode->file,
            'line'       => $anonymousClassNode->line,
            'extends'    => $anonymousClassNode->extends,
            'implements' => $anonymousClassNode->implements,
            'traits'     => $anonymousClassNode->traits,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<AnonymousClassNode>|null
     */
    private function anonymousClassNodesFromPayload(array $payload): ?array
    {
        $rawNodes = $payload['anonymousClassNodes'] ?? [];

        if (! is_array($rawNodes)) {
            return null;
        }

        $anonymousClassNodes = [];

        foreach ($rawNodes as $rawNode) {
            if (! is_array($rawNode)) {
                return null;
            }

            $file       = $rawNode['file'] ?? null;
            $line       = $rawNode['line'] ?? null;
            $extends    = $rawNode['extends'] ?? null;
            $implements = $rawNode['implements'] ?? [];
            $traits     = $rawNode['traits'] ?? [];

            if (! is_string($file) || ! is_int($line) || ($extends !== null && ! is_string($extends))) {
                return null;
            }

            if (! $this->isStringArray($implements) || ! $this->isStringArray($traits)) {
                return null;
            }

            $anonymousClassNodes[] = new AnonymousClassNode(
                file:       $file,
                line:       $line,
                extends:    $extends,
                implements: $implements,
                traits:     $traits,
            );
        }

        return $anonymousClassNodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function functionNodeToArray(FunctionNode $functionNode): array
    {
        return ['functionName' => $functionNode->functionName] + $this->functionLikeBodyToArray(
            $functionNode->line,
            $functionNode->layer,
            $functionNode->hasReturnType,
            $functionNode->paramCount,
            $functionNode->cyclomaticComplexity,
            $functionNode->lineCount,
            $functionNode->dependencies,
            $functionNode->functionCalls,
            $functionNode->superglobals,
            $functionNode->languageConstructs,
            $functionNode->layers,
        );
    }

    /**
     * The fields FunctionNode and AnonymousFunctionNode share. The file is
     * not stored: the payload belongs to one file, known when loading. Empty
     * lists — the common case for a closure — are left out and default on
     * load, which keeps the many small function-like entries small.
     *
     * @param list<string> $dependencies
     * @param string[]     $functionCalls
     * @param string[]     $superglobals
     * @param string[]     $languageConstructs
     * @param list<string> $layers
     * @return array<string, mixed>
     */
    private function functionLikeBodyToArray(
        int $line,
        ?string $layer,
        bool $hasReturnType,
        int $paramCount,
        int $cyclomaticComplexity,
        int $lineCount,
        array $dependencies,
        array $functionCalls,
        array $superglobals,
        array $languageConstructs,
        array $layers,
    ): array {
        $body = [
            'line'                 => $line,
            'layer'                => $layer,
            'hasReturnType'        => $hasReturnType,
            'paramCount'           => $paramCount,
            'cyclomaticComplexity' => $cyclomaticComplexity,
            'lineCount'            => $lineCount,
        ];

        $lists = [
            'dependencies'       => $dependencies,
            'functionCalls'      => $functionCalls,
            'superglobals'       => $superglobals,
            'languageConstructs' => $languageConstructs,
            'layers'             => $layers,
        ];

        foreach ($lists as $key => $list) {
            if ($list !== []) {
                $body[$key] = array_values($list);
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<FunctionNode>|null
     */
    private function functionNodesFromPayload(array $payload, string $file): ?array
    {
        $rawNodes = $payload['functionNodes'] ?? [];

        if (! is_array($rawNodes)) {
            return null;
        }

        $functionNodes = [];

        foreach ($rawNodes as $rawNode) {
            if (! is_array($rawNode)) {
                return null;
            }

            $functionName = $rawNode['functionName'] ?? null;
            $body         = $this->functionLikeBodyFromArray($rawNode, $file);

            if (! is_string($functionName) || $body === null) {
                return null;
            }

            $functionNodes[] = new FunctionNode(
                functionName:         $functionName,
                file:                 $body['file'],
                line:                 $body['line'],
                layer:                $body['layer'],
                hasReturnType:        $body['hasReturnType'],
                paramCount:           $body['paramCount'],
                cyclomaticComplexity: $body['cyclomaticComplexity'],
                lineCount:            $body['lineCount'],
                dependencies:         $body['dependencies'],
                functionCalls:        $body['functionCalls'],
                superglobals:         $body['superglobals'],
                languageConstructs:   $body['languageConstructs'],
                layers:               $body['layers'],
            );
        }

        return $functionNodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function anonymousFunctionNodeToArray(AnonymousFunctionNode $anonymousFunctionNode): array
    {
        return [
            'isArrowFunction'       => $anonymousFunctionNode->isArrowFunction,
            'isStatic'              => $anonymousFunctionNode->isStatic,
            'enclosingClassName'    => $anonymousFunctionNode->enclosingClassName,
            'enclosingFunctionName' => $anonymousFunctionNode->enclosingFunctionName,
            'usesThis'              => $anonymousFunctionNode->usesThis,
        ] + $this->functionLikeBodyToArray(
            $anonymousFunctionNode->line,
            $anonymousFunctionNode->layer,
            $anonymousFunctionNode->hasReturnType,
            $anonymousFunctionNode->paramCount,
            $anonymousFunctionNode->cyclomaticComplexity,
            $anonymousFunctionNode->lineCount,
            $anonymousFunctionNode->dependencies,
            $anonymousFunctionNode->functionCalls,
            $anonymousFunctionNode->superglobals,
            $anonymousFunctionNode->languageConstructs,
            $anonymousFunctionNode->layers,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<AnonymousFunctionNode>|null
     */
    private function anonymousFunctionNodesFromPayload(array $payload, string $file): ?array
    {
        $rawNodes = $payload['anonymousFunctionNodes'] ?? [];

        if (! is_array($rawNodes)) {
            return null;
        }

        $anonymousFunctionNodes = [];

        foreach ($rawNodes as $rawNode) {
            if (! is_array($rawNode)) {
                return null;
            }

            $isArrowFunction       = $rawNode['isArrowFunction'] ?? null;
            $isStatic              = $rawNode['isStatic'] ?? null;
            $enclosingClassName    = $rawNode['enclosingClassName'] ?? null;
            $enclosingFunctionName = $rawNode['enclosingFunctionName'] ?? null;
            $usesThis              = $rawNode['usesThis'] ?? null;
            $body                  = $this->functionLikeBodyFromArray($rawNode, $file);

            if (
                ! is_bool($isArrowFunction)
                || ! is_bool($isStatic)
                || ! is_bool($usesThis)
                || ($enclosingClassName !== null && ! is_string($enclosingClassName))
                || ($enclosingFunctionName !== null && ! is_string($enclosingFunctionName))
                || $body === null
            ) {
                return null;
            }

            $anonymousFunctionNodes[] = new AnonymousFunctionNode(
                file:                  $body['file'],
                line:                  $body['line'],
                layer:                 $body['layer'],
                isArrowFunction:       $isArrowFunction,
                isStatic:              $isStatic,
                enclosingClassName:    $enclosingClassName,
                enclosingFunctionName: $enclosingFunctionName,
                usesThis:              $usesThis,
                hasReturnType:         $body['hasReturnType'],
                paramCount:            $body['paramCount'],
                cyclomaticComplexity:  $body['cyclomaticComplexity'],
                lineCount:             $body['lineCount'],
                dependencies:          $body['dependencies'],
                functionCalls:         $body['functionCalls'],
                superglobals:          $body['superglobals'],
                languageConstructs:    $body['languageConstructs'],
                layers:                $body['layers'],
            );
        }

        return $anonymousFunctionNodes;
    }

    /**
     * The fields FunctionNode and AnonymousFunctionNode share, type-checked.
     *
     * @param array<mixed, mixed> $node
     * @return array{
     *     file: string,
     *     line: int,
     *     layer: string|null,
     *     hasReturnType: bool,
     *     paramCount: int,
     *     cyclomaticComplexity: int,
     *     lineCount: int,
     *     dependencies: list<string>,
     *     functionCalls: list<string>,
     *     superglobals: list<string>,
     *     languageConstructs: list<string>,
     *     layers: list<string>
     * }|null
     */
    private function functionLikeBodyFromArray(array $node, string $file): ?array
    {
        $line                 = $node['line'] ?? null;
        $layer                = $node['layer'] ?? null;
        $hasReturnType        = $node['hasReturnType'] ?? null;
        $paramCount           = $node['paramCount'] ?? null;
        $cyclomaticComplexity = $node['cyclomaticComplexity'] ?? null;
        $lineCount            = $node['lineCount'] ?? null;
        $dependencies         = $node['dependencies'] ?? [];
        $functionCalls        = $node['functionCalls'] ?? [];
        $superglobals         = $node['superglobals'] ?? [];
        $languageConstructs   = $node['languageConstructs'] ?? [];
        $layers               = $node['layers'] ?? [];

        if (
            ! is_int($line)
            || ($layer !== null && ! is_string($layer))
            || ! is_bool($hasReturnType)
            || ! is_int($paramCount)
            || ! is_int($cyclomaticComplexity)
            || ! is_int($lineCount)
            || ! $this->isStringArray($dependencies)
            || ! $this->isStringArray($functionCalls)
            || ! $this->isStringArray($superglobals)
            || ! $this->isStringArray($languageConstructs)
            || ! $this->isStringArray($layers)
        ) {
            return null;
        }

        return [
            'file'                 => $file,
            'line'                 => $line,
            'layer'                => $layer,
            'hasReturnType'        => $hasReturnType,
            'paramCount'           => $paramCount,
            'cyclomaticComplexity' => $cyclomaticComplexity,
            'lineCount'            => $lineCount,
            'dependencies'         => array_values($dependencies),
            'functionCalls'        => array_values($functionCalls),
            'superglobals'         => array_values($superglobals),
            'languageConstructs'   => array_values($languageConstructs),
            'layers'               => array_values($layers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classNodeToArray(ClassNode $classNode): array
    {
        return [
            'className'          => $classNode->className,
            'file'               => $classNode->file,
            'line'               => $classNode->line,
            'layer'              => $classNode->layer,
            'extends'            => $classNode->extends,
            'isAbstract'         => $classNode->isAbstract,
            'isFinal'            => $classNode->isFinal,
            'isInterface'        => $classNode->isInterface,
            'isTrait'            => $classNode->isTrait,
            'isEnum'             => $classNode->isEnum,
            'isReadonly'         => $classNode->isReadonly,
            'dependencies'       => $classNode->dependencies,
            'implements'         => array_values($classNode->implements),
            'interfaceExtends'   => array_values($classNode->interfaceExtends),
            'parentClasses'      => $classNode->parentClasses,
            'parentInterfaces'   => $classNode->parentInterfaces,
            'traits'             => array_values($classNode->traits),
            'methods'            => array_map($this->methodNodeToArray(...), $classNode->methods),
            'constants'          => array_map($this->constantNodeToArray(...), $classNode->constants),
            'properties'         => array_map($this->propertyNodeToArray(...), $classNode->properties),
            'enumCases'          => array_map($this->enumCaseNodeToArray(...), $classNode->enumCases),
            'enumBackingType'    => $classNode->enumBackingType,
            'functionCalls'      => array_values($classNode->functionCalls),
            'superglobals'       => array_values($classNode->superglobals),
            'languageConstructs' => array_values($classNode->languageConstructs),
            'layers'             => $classNode->layers,
        ];
    }

    /**
     * @param array<mixed, mixed> $node
     */
    private function classNodeFromArray(array $node): ?ClassNode
    {
        $className          = $node['className'] ?? null;
        $file               = $node['file'] ?? null;
        $line               = $node['line'] ?? null;
        $layer              = $node['layer'] ?? null;
        $extends            = $node['extends'] ?? null;
        $isAbstract         = $node['isAbstract'] ?? null;
        $isFinal            = $node['isFinal'] ?? null;
        $isInterface        = $node['isInterface'] ?? null;
        $isTrait            = $node['isTrait'] ?? null;
        $isEnum             = $node['isEnum'] ?? null;
        $isReadonly         = $node['isReadonly'] ?? null;
        $dependencies       = $node['dependencies'] ?? null;
        $implements         = $node['implements'] ?? null;
        $interfaceExtends   = $node['interfaceExtends'] ?? [];
        $parentClasses      = $node['parentClasses'] ?? [];
        $parentInterfaces   = $node['parentInterfaces'] ?? [];
        $traits             = $node['traits'] ?? [];
        $rawMethods         = $node['methods'] ?? null;
        $rawConstants       = $node['constants'] ?? null;
        $rawProperties      = $node['properties'] ?? null;
        $rawEnumCases       = $node['enumCases'] ?? [];
        $enumBackingType    = $node['enumBackingType'] ?? null;
        $functionCalls      = $node['functionCalls'] ?? null;
        $superglobals       = $node['superglobals'] ?? null;
        $languageConstructs = $node['languageConstructs'] ?? [];
        $layers             = $node['layers'] ?? [];

        if (
            ! is_string($className)
            || ! is_string($file)
            || ! is_int($line)
            || $layer !== null && ! is_string($layer)
            || $extends !== null && ! is_string($extends)
            || ! is_bool($isAbstract)
            || ! is_bool($isFinal)
            || ! is_bool($isInterface)
            || ! is_bool($isTrait)
            || ! is_bool($isEnum)
            || ! is_bool($isReadonly)
            || ! $this->isStringArray($dependencies)
            || ! $this->isStringArray($implements)
            || ! $this->isStringArray($interfaceExtends)
            || ! $this->isStringArray($parentClasses)
            || ! $this->isStringArray($parentInterfaces)
            || ! $this->isStringArray($traits)
            || ! is_array($rawMethods)
            || ! is_array($rawConstants)
            || ! is_array($rawProperties)
            || ! $this->isStringArray($functionCalls)
            || ! $this->isStringArray($superglobals)
            || ! $this->isStringArray($languageConstructs)
            || ! $this->isStringArray($layers)
        ) {
            return null;
        }

        $methods = [];

        foreach ($rawMethods as $rawMethod) {
            if (! is_array($rawMethod)) {
                return null;
            }

            $methodNode = $this->methodNodeFromArray($rawMethod);

            if (! $methodNode instanceof MethodNode) {
                return null;
            }

            $methods[] = $methodNode;
        }

        $constants = [];

        foreach ($rawConstants as $rawConstant) {
            if (! is_array($rawConstant)) {
                return null;
            }

            $constantNode = $this->constantNodeFromArray($rawConstant);

            if (! $constantNode instanceof ConstantNode) {
                return null;
            }

            $constants[] = $constantNode;
        }

        $properties = [];

        foreach ($rawProperties as $rawProperty) {
            if (! is_array($rawProperty)) {
                return null;
            }

            $propertyNode = $this->propertyNodeFromArray($rawProperty);

            if (! $propertyNode instanceof PropertyNode) {
                return null;
            }

            $properties[] = $propertyNode;
        }

        if (! is_array($rawEnumCases) || ($enumBackingType !== null && ! is_string($enumBackingType))) {
            return null;
        }

        $enumCases = [];

        foreach ($rawEnumCases as $rawEnumCase) {
            if (! is_array($rawEnumCase)) {
                return null;
            }

            $enumCaseNode = $this->enumCaseNodeFromArray($rawEnumCase);

            if (! $enumCaseNode instanceof EnumCaseNode) {
                return null;
            }

            $enumCases[] = $enumCaseNode;
        }

        return new ClassNode(
            className:          $className,
            file:               $file,
            line:               $line,
            layer:              $layer,
            extends:            $extends,
            isAbstract:         $isAbstract,
            isFinal:            $isFinal,
            isInterface:        $isInterface,
            isReadonly:         $isReadonly,
            isTrait:            $isTrait,
            dependencies:       array_values($dependencies),
            implements:         array_values($implements),
            traits:             array_values($traits),
            methods:            $methods,
            constants:          $constants,
            properties:         $properties,
            functionCalls:      array_values($functionCalls),
            superglobals:       array_values($superglobals),
            languageConstructs: array_values($languageConstructs),
            layers:             array_values($layers),
            isEnum:             $isEnum,
            interfaceExtends:   array_values($interfaceExtends),
            parentClasses:      array_values($parentClasses),
            parentInterfaces:   array_values($parentInterfaces),
            enumCases:          $enumCases,
            enumBackingType:    $enumBackingType,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function methodNodeToArray(MethodNode $methodNode): array
    {
        return [
            'name'                  => $methodNode->name,
            'visibility'            => $methodNode->visibility,
            'hasReturnType'         => $methodNode->hasReturnType,
            'isStatic'              => $methodNode->isStatic,
            'paramCount'            => $methodNode->paramCount,
            'cyclomaticComplexity'  => $methodNode->cyclomaticComplexity,
            'lineCount'             => $methodNode->lineCount,
            'hasExplicitVisibility' => $methodNode->hasExplicitVisibility,
            'line'                  => $methodNode->line,
            'isMagic'               => $methodNode->isMagic,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function constantNodeToArray(ConstantNode $constantNode): array
    {
        return [
            'name'                  => $constantNode->name,
            'visibility'            => $constantNode->visibility,
            'hasExplicitVisibility' => $constantNode->hasExplicitVisibility,
            'line'                  => $constantNode->line,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyNodeToArray(PropertyNode $propertyNode): array
    {
        return [
            'name'                  => $propertyNode->name,
            'visibility'            => $propertyNode->visibility,
            'hasExplicitVisibility' => $propertyNode->hasExplicitVisibility,
            'line'                  => $propertyNode->line,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enumCaseNodeToArray(EnumCaseNode $enumCaseNode): array
    {
        return [
            'name'  => $enumCaseNode->name,
            'line'  => $enumCaseNode->line,
            'value' => $enumCaseNode->value,
        ];
    }

    /**
     * @param array<mixed, mixed> $method
     */
    private function methodNodeFromArray(array $method): ?MethodNode
    {
        if (
            ! is_string($method['name'] ?? null)
            || ! is_string($method['visibility'] ?? null)
            || ! is_bool($method['hasReturnType'] ?? null)
            || ! is_bool($method['isStatic'] ?? null)
            || ! is_int($method['paramCount'] ?? null)
            || ! is_int($method['cyclomaticComplexity'] ?? null)
            || ! is_int($method['lineCount'] ?? null)
            || ! is_bool($method['hasExplicitVisibility'] ?? null)
            || ! is_int($method['line'] ?? null)
            || ! is_bool($method['isMagic'] ?? null)
        ) {
            return null;
        }

        return new MethodNode(
            name:                 $method['name'],
            visibility:           $method['visibility'],
            hasReturnType:        $method['hasReturnType'],
            isStatic:             $method['isStatic'],
            paramCount:           $method['paramCount'],
            cyclomaticComplexity: $method['cyclomaticComplexity'],
            lineCount:            $method['lineCount'],
            hasExplicitVisibility: $method['hasExplicitVisibility'],
            line:                 $method['line'],
            isMagic:              $method['isMagic'],
        );
    }

    /**
     * @param array<mixed, mixed> $constant
     */
    private function constantNodeFromArray(array $constant): ?ConstantNode
    {
        if (
            ! is_string($constant['name'] ?? null)
            || ! is_string($constant['visibility'] ?? null)
            || ! is_bool($constant['hasExplicitVisibility'] ?? null)
            || ! is_int($constant['line'] ?? null)
        ) {
            return null;
        }

        return new ConstantNode(
            name:                 $constant['name'],
            visibility:           $constant['visibility'],
            hasExplicitVisibility: $constant['hasExplicitVisibility'],
            line:                 $constant['line'],
        );
    }

    /**
     * @param array<mixed, mixed> $property
     */
    private function propertyNodeFromArray(array $property): ?PropertyNode
    {
        if (
            ! is_string($property['name'] ?? null)
            || ! is_string($property['visibility'] ?? null)
            || ! is_bool($property['hasExplicitVisibility'] ?? null)
            || ! is_int($property['line'] ?? null)
        ) {
            return null;
        }

        return new PropertyNode(
            name:                 $property['name'],
            visibility:           $property['visibility'],
            hasExplicitVisibility: $property['hasExplicitVisibility'],
            line:                 $property['line'],
        );
    }

    /**
     * @param array<mixed, mixed> $enumCase
     */
    private function enumCaseNodeFromArray(array $enumCase): ?EnumCaseNode
    {
        $value = $enumCase['value'] ?? null;

        if (
            ! is_string($enumCase['name'] ?? null)
            || ! is_int($enumCase['line'] ?? null)
            || ($value !== null && ! is_int($value) && ! is_string($value))
        ) {
            return null;
        }

        return new EnumCaseNode(
            name:  $enumCase['name'],
            line:  $enumCase['line'],
            value: $value,
        );
    }

    /** @return array<string, mixed> */
    private function fileAnalysisToArray(FileAnalysis $fileAnalysis): array
    {
        return [
            'file'                         => $fileAnalysis->file,
            'hasUtf8Bom'                   => $fileAnalysis->hasUtf8Bom,
            'hasValidUtf8'                 => $fileAnalysis->hasValidUtf8,
            'invalidPhpTagLine'            => $fileAnalysis->invalidPhpTagLine,
            'hasValidAst'                  => $fileAnalysis->hasValidAst,
            'declaresSymbols'              => $fileAnalysis->declaresSymbols,
            'hasSideEffects'               => $fileAnalysis->hasSideEffects,
            'sideEffectLine'               => $fileAnalysis->sideEffectLine,
            'nonCanonicalKeywordConstants' => $fileAnalysis->nonCanonicalKeywordConstants,
        ];
    }

    /** @param array<mixed, mixed> $analysis */
    private function fileAnalysisFromArray(array $analysis): ?FileAnalysis
    {
        if (
            ! is_string($analysis['file'] ?? null)
            || ! is_bool($analysis['hasUtf8Bom'] ?? null)
            || ! is_bool($analysis['hasValidUtf8'] ?? null)
            || ! array_key_exists('invalidPhpTagLine', $analysis)
            || ($analysis['invalidPhpTagLine'] !== null && ! is_int($analysis['invalidPhpTagLine']))
            || ! is_bool($analysis['hasValidAst'] ?? null)
            || ! is_bool($analysis['declaresSymbols'] ?? null)
            || ! is_bool($analysis['hasSideEffects'] ?? null)
            || ! is_int($analysis['sideEffectLine'] ?? null)
            || ! $this->isKeywordConstantList($analysis['nonCanonicalKeywordConstants'] ?? null)
        ) {
            return null;
        }

        return new FileAnalysis(
            file: $analysis['file'],
            hasUtf8Bom: $analysis['hasUtf8Bom'],
            hasValidUtf8: $analysis['hasValidUtf8'],
            invalidPhpTagLine: $analysis['invalidPhpTagLine'],
            hasValidAst: $analysis['hasValidAst'],
            declaresSymbols: $analysis['declaresSymbols'],
            hasSideEffects: $analysis['hasSideEffects'],
            sideEffectLine: $analysis['sideEffectLine'],
            nonCanonicalKeywordConstants: $analysis['nonCanonicalKeywordConstants'],
        );
    }

    /**
     * @phpstan-assert-if-true list<array{int, string}> $value
     */
    private function isKeywordConstantList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $keywordConstant) {
            if (
                ! is_array($keywordConstant)
                || count($keywordConstant) !== 2
                || ! is_int($keywordConstant[0] ?? null)
                || ! is_string($keywordConstant[1] ?? null)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed, mixed> $array
     * @phpstan-assert-if-true array<string, mixed> $array
     */
    private function hasOnlyStringKeys(array $array): bool
    {
        foreach (array_keys($array) as $key) {
            if (! is_string($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @phpstan-assert-if-true array<int, string> $value
     */
    private function isStringArray(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (! is_int($key) || ! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    private function path(string $key): string
    {
        return sprintf('%s/%s.json', $this->cacheDirectory, $key);
    }

    private function analysisNodesKey(string $file, string $namespace): string
    {
        return 'analysis-nodes-' . hash('xxh128', $namespace . "\0" . $file);
    }

    /**
     * @return array<string, mixed>
     */
    private function fileMetadata(string $file, string $namespace): array
    {
        return [
            'namespace' => $namespace,
            'file'      => $file,
            'hash'      => $this->fileHashProvider->hash($file),
        ];
    }
}
