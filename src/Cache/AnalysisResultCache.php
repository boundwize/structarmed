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
use LogicException;
use Throwable;

use function array_fill_keys;
use function array_is_list;
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
use function serialize;
use function sprintf;
use function unlink;
use function unserialize;

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
    public const FORMAT_VERSION = 3;

    /**
     * Every class an analysis-node payload may contain. unserialize() turns
     * anything else into an incomplete-class stub, which the load path
     * rejects, so a tampered or foreign payload can never instantiate an
     * arbitrary class.
     */
    private const ALLOWED_PAYLOAD_CLASSES = [
        ClassNode::class,
        MethodNode::class,
        ConstantNode::class,
        PropertyNode::class,
        EnumCaseNode::class,
        AnonymousClassNode::class,
        FunctionNode::class,
        AnonymousFunctionNode::class,
        FileAnalysis::class,
    ];

    private readonly string $cacheDirectory;

    /** Hash of the project composer.json: its PSR-4 mappings decide layer assignments. */
    private readonly string $composerHash;

    private bool $isCacheInitialised = false;

    public function __construct(
        string $basePath,
        private readonly FileHashProvider $fileHashProvider,
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

        return $this->analysisNodeResultFromPayload($payload);
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

        $fileAnalysis = $payload['fileAnalysis'] ?? null;

        if (! $fileAnalysis instanceof FileAnalysis) {
            return null;
        }

        $result = $this->analysisNodeResultFromPayload($payload);

        if ($result === null) {
            return null;
        }

        $result['fileAnalysis'] = $fileAnalysis;

        return $result;
    }

    /**
     * @param array<mixed, mixed> $payload
     * @return array{
     *     classNodes: list<ClassNode>,
     *     anonymousClassNodes: list<AnonymousClassNode>,
     *     fileReferences: list<string>,
     *     fileInstantiations: list<string>,
     *     functionNodes: list<FunctionNode>,
     *     anonymousFunctionNodes: list<AnonymousFunctionNode>
     * }|null
     */
    private function analysisNodeResultFromPayload(array $payload): ?array
    {
        $classNodes             = $payload['classNodes'] ?? [];
        $anonymousClassNodes    = $payload['anonymousClassNodes'] ?? [];
        $fileReferences         = $payload['fileReferences'] ?? [];
        $fileInstantiations     = $payload['fileInstantiations'] ?? [];
        $functionNodes          = $payload['functionNodes'] ?? [];
        $anonymousFunctionNodes = $payload['anonymousFunctionNodes'] ?? [];

        if (
            ! $this->isListOf($classNodes, ClassNode::class)
            || ! $this->isListOf($anonymousClassNodes, AnonymousClassNode::class)
            || ! $this->isStringList($fileReferences)
            || ! $this->isStringList($fileInstantiations)
            || ! $this->isListOf($functionNodes, FunctionNode::class)
            || ! $this->isListOf($anonymousFunctionNodes, AnonymousFunctionNode::class)
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

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private function isStringList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Nested nodes live in readonly properties of these top-level nodes, so
     * checking the top-level lists is enough to reject a payload that
     * unserialize() could not fully restore.
     *
     * @template T of object
     * @param class-string<T> $class
     * @phpstan-assert-if-true list<T> $value
     */
    private function isListOf(mixed $value, string $class): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! $item instanceof $class) {
                return false;
            }
        }

        return true;
    }

    /** @return array<mixed, mixed>|null */
    private function analysisNodePayload(string $file, string $namespace): ?array
    {
        $path = $this->analysisNodesPath($file, $namespace);

        if (! file_exists($path)) {
            return null;
        }

        try {
            // A truncated or garbled file makes unserialize() warn and return
            // false; a wrong-typed property on a node throws. Either way it is
            // a cache miss, never an error shown to the user.
            $payload = @unserialize(
                (string) file_get_contents($path),
                ['allowed_classes' => self::ALLOWED_PAYLOAD_CLASSES]
            );
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload) || ($payload['metadata'] ?? null) !== $this->fileMetadata($file, $namespace)) {
            return null;
        }

        return $payload;
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
     * The nodes are serialized as they are, so a cached ClassNode must hold
     * extraction-time facts only: the analyser only ever raises its usage
     * flags and parent lists, and would never correct a stale true on load.
     *
     * @param list<ClassNode>          $classNodes
     * @param list<AnonymousClassNode> $anonymousClassNodes
     * @param list<string>             $fileReferences Class-like references made outside any
     *                                                 named class-like scope in this file
     * @param list<string>             $fileInstantiations Class-like instantiations in this file
     * @param list<FunctionNode>          $functionNodes
     * @param list<AnonymousFunctionNode> $anonymousFunctionNodes
     * @throws LogicException When a ClassNode already carries post-analysis state.
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
        foreach ($classNodes as $classNode) {
            if (
                $classNode->parentClasses !== []
                || $classNode->parentInterfaces !== []
                || $classNode->isExtended
                || $classNode->isImplemented
                || $classNode->isReferenced
                || $classNode->isInstantiated
            ) {
                throw new LogicException(sprintf(
                    'ClassNode [%s] carries post-analysis state and cannot be cached.',
                    $classNode->className,
                ));
            }
        }

        $this->ensureCacheInitialised();

        $payload = [
            'metadata'            => $this->fileMetadata($file, $namespace),
            'classNodes'          => $classNodes,
            'anonymousClassNodes' => $anonymousClassNodes,
            'fileReferences'      => $fileReferences,
            'fileInstantiations'  => $fileInstantiations,
        ];

        // Most files declare no function-likes; leave their keys out entirely.
        if ($functionNodes !== []) {
            $payload['functionNodes'] = $functionNodes;
        }

        if ($anonymousFunctionNodes !== []) {
            $payload['anonymousFunctionNodes'] = $anonymousFunctionNodes;
        }

        if ($fileAnalysis instanceof FileAnalysis) {
            $payload['fileAnalysis'] = $fileAnalysis;
        }

        file_put_contents($this->analysisNodesPath($file, $namespace), serialize($payload));
    }

    /**
     * @return array<mixed, mixed>|null
     */
    private function read(string $key): ?array
    {
        return $this->readPath($this->path($key));
    }

    /**
     * @return array<mixed, mixed>|null
     */
    private function readPath(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) ? $payload : null;
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

    private function path(string $key): string
    {
        return sprintf('%s/%s.json', $this->cacheDirectory, $key);
    }

    private function analysisNodesPath(string $file, string $namespace): string
    {
        return sprintf('%s/%s.bin', $this->cacheDirectory, $this->analysisNodesKey($file, $namespace));
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
