<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Cache;

use Boundwize\StructArmed\Analyser\ClassNode;
use Boundwize\StructArmed\Analyser\ConstantNode;
use Boundwize\StructArmed\Analyser\MethodNode;
use Boundwize\StructArmed\Analyser\PropertyNode;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
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
use function preg_match;
use function rmdir;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strval;
use function sys_get_temp_dir;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final readonly class AnalysisResultCache
{
    private string $cacheDirectory;

    public function __construct(string $basePath, ?string $cacheDirectory = null)
    {
        $this->cacheDirectory = $cacheDirectory
            ? $this->resolveCacheDirectory($basePath, $cacheDirectory)
            : str_replace('\\', '/', sys_get_temp_dir()) . '/structarmed/cache/' . hash('xxh128', $basePath);
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
        if (! is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0777, true);
        }

        file_put_contents($this->path($key), json_encode([
            'metadata'   => $metadata,
            'violations' => $ruleViolationCollection->toArray(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function clear(): void
    {
        if (! is_dir($this->cacheDirectory)) {
            return;
        }

        foreach (array_map(strval(...), glob($this->cacheDirectory . '/*') ?: []) as $path) {
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

    public function hasDifferentConfig(string $configHash): bool
    {
        if (! is_dir($this->cacheDirectory)) {
            return false;
        }

        foreach (array_map(strval(...), glob($this->cacheDirectory . '/*') ?: []) as $path) {
            if (is_dir($path)) {
                continue;
            }

            $payload = $this->readPath($path);

            if ($payload === null) {
                continue;
            }

            $metadata = $payload['metadata'] ?? null;

            if (! is_array($metadata)) {
                continue;
            }

            if (! isset($metadata['configHash'])) {
                continue;
            }

            if ($metadata['configHash'] !== $configHash) {
                return true;
            }
        }

        return false;
    }

    public function hasDifferentComposerGeneratedVersion(string $composerGeneratedVersionHash): bool
    {
        if (! is_dir($this->cacheDirectory)) {
            return false;
        }

        foreach (array_map(strval(...), glob($this->cacheDirectory . '/*') ?: []) as $path) {
            if (is_dir($path)) {
                continue;
            }

            $payload = $this->readPath($path);

            if ($payload === null) {
                continue;
            }

            $metadata = $payload['metadata'] ?? null;

            if (! is_array($metadata)) {
                continue;
            }

            if (! array_key_exists('composerGeneratedVersionHash', $metadata)) {
                continue;
            }

            if ($metadata['composerGeneratedVersionHash'] !== $composerGeneratedVersionHash) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ClassNode>|null
     */
    public function loadClassNodes(string $file, string $namespace): ?array
    {
        $payload = $this->read($this->classNodesKey($file, $namespace));

        if ($payload === null) {
            return null;
        }

        if (($payload['metadata'] ?? null) !== $this->fileMetadata($file, $namespace)) {
            return null;
        }

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
     * @param list<ClassNode> $classNodes
     */
    public function storeClassNodes(string $file, string $namespace, array $classNodes): void
    {
        if (! is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0777, true);
        }

        file_put_contents($this->path($this->classNodesKey($file, $namespace)), json_encode([
            'metadata' => $this->fileMetadata($file, $namespace),
            'nodes'    => array_map($this->classNodeToArray(...), $classNodes),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
        if (! $this->hasOnlyStringKeys($violation)) {
            return null;
        }

        $ruleKey   = $violation['rule'] ?? null;
        $message   = $violation['message'] ?? null;
        $file      = $violation['file'] ?? null;
        $line      = $violation['line'] ?? null;
        $className = $violation['class'] ?? null;
        $layer     = $violation['layer'] ?? null;

        if (
            ! is_string($ruleKey)
            || ! is_string($message)
            || ! is_string($file)
            || ! is_int($line)
            || ! is_string($className)
            || ($layer !== null && ! is_string($layer))
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
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function classNodeToArray(ClassNode $classNode): array
    {
        return [
            'className'     => $classNode->className,
            'file'          => $classNode->file,
            'line'          => $classNode->line,
            'layer'         => $classNode->layer,
            'extends'       => $classNode->extends,
            'isAbstract'    => $classNode->isAbstract,
            'isFinal'       => $classNode->isFinal,
            'isInterface'   => $classNode->isInterface,
            'isTrait'       => $classNode->isTrait,
            'isEnum'        => $classNode->isEnum,
            'isReadonly'    => $classNode->isReadonly,
            'dependencies'  => $classNode->dependencies,
            'implements'    => array_values($classNode->implements),
            'traits'        => array_values($classNode->traits),
            'methods'       => array_map($this->methodNodeToArray(...), $classNode->methods),
            'constants'     => array_map($this->constantNodeToArray(...), $classNode->constants),
            'properties'    => array_map($this->propertyNodeToArray(...), $classNode->properties),
            'functionCalls' => array_values($classNode->functionCalls),
            'superglobals'  => array_values($classNode->superglobals),
            'layers'        => $classNode->layers,
        ];
    }

    /**
     * @param array<mixed, mixed> $node
     */
    private function classNodeFromArray(array $node): ?ClassNode
    {
        if (! $this->hasOnlyStringKeys($node)) {
            return null;
        }

        $className     = $node['className'] ?? null;
        $file          = $node['file'] ?? null;
        $line          = $node['line'] ?? null;
        $layer         = $node['layer'] ?? null;
        $extends       = $node['extends'] ?? null;
        $isAbstract    = $node['isAbstract'] ?? null;
        $isFinal       = $node['isFinal'] ?? null;
        $isInterface   = $node['isInterface'] ?? null;
        $isTrait       = $node['isTrait'] ?? null;
        $isEnum        = $node['isEnum'] ?? null;
        $isReadonly    = $node['isReadonly'] ?? null;
        $dependencies  = $node['dependencies'] ?? null;
        $implements    = $node['implements'] ?? null;
        $traits        = $node['traits'] ?? [];
        $rawMethods    = $node['methods'] ?? null;
        $rawConstants  = $node['constants'] ?? null;
        $rawProperties = $node['properties'] ?? null;
        $functionCalls = $node['functionCalls'] ?? null;
        $superglobals  = $node['superglobals'] ?? null;
        $layers        = $node['layers'] ?? [];

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
            || ! $this->isStringArray($traits)
            || ! is_array($rawMethods)
            || ! is_array($rawConstants)
            || ! is_array($rawProperties)
            || ! $this->isStringArray($functionCalls)
            || ! $this->isStringArray($superglobals)
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

        return new ClassNode(
            className:     $className,
            file:          $file,
            line:          $line,
            layer:         $layer,
            extends:       $extends,
            isAbstract:    $isAbstract,
            isFinal:       $isFinal,
            isInterface:   $isInterface,
            isReadonly:    $isReadonly,
            isTrait:       $isTrait,
            dependencies:  array_values($dependencies),
            implements:    array_values($implements),
            traits:        array_values($traits),
            methods:       $methods,
            constants:     $constants,
            properties:    $properties,
            functionCalls: array_values($functionCalls),
            superglobals:  array_values($superglobals),
            layers:        array_values($layers),
            isEnum:        $isEnum,
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
     * @param array<mixed, mixed> $method
     */
    private function methodNodeFromArray(array $method): ?MethodNode
    {
        if (! $this->hasOnlyStringKeys($method)) {
            return null;
        }

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
        );
    }

    /**
     * @param array<mixed, mixed> $constant
     */
    private function constantNodeFromArray(array $constant): ?ConstantNode
    {
        if (! $this->hasOnlyStringKeys($constant)) {
            return null;
        }

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
        if (! $this->hasOnlyStringKeys($property)) {
            return null;
        }

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

    private function classNodesKey(string $file, string $namespace): string
    {
        return 'class-nodes-' . hash('xxh128', $namespace . "\0" . $file);
    }

    /**
     * @return array<string, mixed>
     */
    private function fileMetadata(string $file, string $namespace): array
    {
        return [
            'namespace' => $namespace,
            'file'      => $file,
            'hash'      => hash('xxh128', (string) file_get_contents($file)),
        ];
    }

    private function resolveCacheDirectory(string $basePath, string $cacheDirectory): string
    {
        $cacheDirectory = str_replace('\\', '/', $cacheDirectory);

        if ($this->isAbsolutePath($cacheDirectory)) {
            return $cacheDirectory;
        }

        return sprintf('%s/%s', $basePath, $cacheDirectory);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }
}
