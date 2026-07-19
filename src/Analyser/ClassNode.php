<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function array_filter;
use function end;
use function explode;
use function in_array;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_starts_with;

final class ClassNode
{
    /** @var list<string> */
    public readonly array $layers;

    /**
     * @param list<string>   $dependencies        Fully-qualified class names this class depends on
     * @param string[]       $implements          Interface names this class implements
     * @param string[]       $traits              Trait names this class uses
     * @param MethodNode[]   $methods             Methods of this class
     * @param ConstantNode[] $constants           Constants of this class
     * @param PropertyNode[] $properties          Properties of this class
     * @param string[]       $functionCalls       Functions called within this class
     * @param string[]       $superglobals        Superglobals accessed ($_GET, $_POST, etc.)
     * @param string[]       $languageConstructs  Language constructs used (exit, die, etc.)
     * @param list<string>   $layers              All layer names this class belongs to; defaults to [$layer]
     * @param string[]       $interfaceExtends    Interface names this interface extends
     * @param list<string>   $parentClasses       Direct and transitive parent class names
     * @param list<string>   $parentInterfaces    Direct and transitive implemented or extended interface names
     */
    public function __construct(
        public readonly string $className,
        public readonly string $file,
        public readonly int $line,
        public readonly ?string $layer,
        public readonly ?string $extends,
        public readonly bool $isAbstract,
        public readonly bool $isFinal,
        public readonly bool $isInterface,
        public readonly bool $isReadonly,
        public readonly bool $isTrait = false,
        public readonly array $dependencies = [],
        public readonly array $implements = [],
        public readonly array $traits = [],
        public readonly array $methods = [],
        public readonly array $constants = [],
        public readonly array $properties = [],
        public readonly array $functionCalls = [],
        public readonly array $superglobals = [],
        public readonly array $languageConstructs = [],
        array $layers = [],
        public readonly bool $isEnum = false,
        public readonly array $interfaceExtends = [],
        public array $parentClasses = [],
        public array $parentInterfaces = [],
        public bool $isExtended = false,
    ) {
        $this->layers = $layers ?: array_filter([$this->layer]);
    }

    /**
     * @param list<string> $parentClasses
     * @param list<string> $parentInterfaces
     */
    public function setRecursiveParents(array $parentClasses, array $parentInterfaces): void
    {
        $this->parentClasses    = $parentClasses;
        $this->parentInterfaces = $parentInterfaces;
    }

    /**
     * Whether another scanned class extends this class. Computed by the analyser
     * for rules implementing ExtendedClassAwareRuleInterface; false otherwise.
     */
    public function setExtended(bool $isExtended): void
    {
        $this->isExtended = $isExtended;
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

    public function nameMatches(string $pattern, bool $isFullName = false): bool
    {
        return (bool) preg_match($pattern, $isFullName ? $this->className : $this->shortName());
    }

    public function dependsOn(string $class): bool
    {
        return in_array($class, $this->dependencies, true);
    }

    public function dependsOnNamespace(string $namespace): bool
    {
        $prefix = rtrim($namespace, '\\') . '\\';

        foreach ($this->dependencies as $dependency) {
            if (str_starts_with($dependency, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function implementsInterface(string $interface): bool
    {
        return in_array($interface, $this->implements, true)
            || in_array($interface, $this->parentInterfaces, true);
    }

    public function extendsClass(string $class): bool
    {
        return $this->extends === $class
            || in_array($class, $this->parentClasses, true);
    }

    public function extendsInterface(string $interface): bool
    {
        return in_array($interface, $this->interfaceExtends, true)
            || in_array($interface, $this->parentInterfaces, true);
    }

    public function callsFunction(string $function): bool
    {
        return in_array($function, $this->functionCalls, true);
    }

    public function usesLanguageConstruct(string $construct): bool
    {
        if (in_array($construct, $this->languageConstructs, true)) {
            return true;
        }

        // `die` is a pure alias of `exit`, so banning either spelling catches both.
        return match ($construct) {
            'exit'  => in_array('die', $this->languageConstructs, true),
            'die'   => in_array('exit', $this->languageConstructs, true),
            default => false,
        };
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
