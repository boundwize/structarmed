<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function array_filter;
use function class_exists;
use function end;
use function enum_exists;
use function explode;
use function in_array;
use function interface_exists;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function trait_exists;

final readonly class ClassNode
{
    /** @var list<string> */
    public array $layers;

    /**
     * @param list<string>   $dependencies   Fully-qualified class names this class depends on
     * @param string[]       $implements     Interface names this class implements
     * @param string[]       $traits         Trait names this class uses
     * @param MethodNode[]   $methods        Methods of this class
     * @param ConstantNode[] $constants      Constants of this class
     * @param PropertyNode[] $properties     Properties of this class
     * @param string[]       $functionCalls  Functions called within this class
     * @param string[]       $superglobals   Superglobals accessed ($_GET, $_POST, etc.)
     * @param list<string>   $layers         All layer names this class belongs to; defaults to [$layer]
     */
    public function __construct(
        public string $className,
        public string $file,
        public int $line,
        public ?string $layer,
        public ?string $extends,
        public bool $isAbstract,
        public bool $isFinal,
        public bool $isInterface,
        public bool $isReadonly,
        public bool $isTrait = false,
        public array $dependencies = [],
        public array $implements = [],
        public array $traits = [],
        public array $methods = [],
        public array $constants = [],
        public array $properties = [],
        public array $functionCalls = [],
        public array $superglobals = [],
        array $layers = [],
        public bool $isEnum = false,
    ) {
        $this->layers = $layers ?: array_filter([$this->layer]);
    }

    public function shortName(): string
    {
        $parts = explode('\\', $this->className);

        return end($parts);
    }

    public function isInLayer(string $layer): bool
    {
        return in_array($layer, $this->layers, true);
    }

    public function isClass(): bool
    {
        return ! $this->isInterface && ! $this->isTrait && ! $this->isEnum;
    }

    public function nameEndsWith(string $suffix): bool
    {
        return str_ends_with($this->shortName(), $suffix);
    }

    public function nameStartsWith(string $prefix): bool
    {
        return str_starts_with($this->shortName(), $prefix);
    }

    public function nameMatches(string $pattern): bool
    {
        return (bool) preg_match($pattern, $this->shortName());
    }

    public function dependsOn(string $classOrNamespace): bool
    {
        if (in_array($classOrNamespace, $this->dependencies, true)) {
            return true;
        }

        $isMayBeClassLike = ! str_ends_with($classOrNamespace, '\\');
        $namespace        = rtrim($classOrNamespace, '\\') . '\\';

        foreach ($this->dependencies as $dependency) {
            if (str_starts_with($dependency, $namespace)) {
                return ! $this->isLoadedClassLike($classOrNamespace, $isMayBeClassLike);
            }
        }

        return false;
    }

    private function isLoadedClassLike(string $name, bool $isMayBeClassLike): bool
    {
        return $isMayBeClassLike && (class_exists($name, false)
            || interface_exists($name, false)
            || trait_exists($name, false)
            || enum_exists($name, false));
    }

    public function implementsInterface(string $interface): bool
    {
        return in_array($interface, $this->implements, true);
    }

    public function callsFunction(string $function): bool
    {
        return in_array($function, $this->functionCalls, true);
    }

    public function accessesSuperglobals(): bool
    {
        return $this->superglobals !== [];
    }

    public function constructorParamCount(): int
    {
        foreach ($this->methods as $method) {
            if ($method->name === '__construct') {
                return $method->paramCount;
            }
        }

        return 0;
    }
}
