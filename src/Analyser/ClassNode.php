<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function array_filter;
use function in_array;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strcasecmp;
use function strrpos;
use function substr;

final class ClassNode
{
    /** @var list<string> */
    public readonly array $layers;

    /**
     * @param list<string>   $dependencies        Fully-qualified class, function, or constant dependencies
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
     * @param EnumCaseNode[] $enumCases           Cases of this enum
     * @param string|null    $enumBackingType     Backing type for a backed enum, null otherwise
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
        public bool $isImplemented = false,
        public bool $isReferenced = false,
        public bool $isInstantiated = false,
        public readonly array $enumCases = [],
        public readonly ?string $enumBackingType = null,
    ) {
        $this->layers = $layers ?: array_filter([$this->layer]);
    }

    public function isBackedEnum(): bool
    {
        return $this->isEnum && $this->enumBackingType !== null;
    }

    public function getType(): string
    {
        if ($this->isInterface) {
            return 'Interface';
        }

        if ($this->isTrait) {
            return 'Trait';
        }

        if ($this->isEnum) {
            return 'Enum';
        }

        return 'Class';
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

    /**
     * Whether another scanned class implements this interface (directly or
     * through inheritance) or another scanned interface extends it. Computed by
     * the analyser for rules implementing UsedInterfaceAwareRuleInterface;
     * false otherwise.
     */
    public function setImplemented(bool $isImplemented): void
    {
        $this->isImplemented = $isImplemented;
    }

    /**
     * Whether another scanned class-like references this class-like — as a
     * trait it uses, or as a dependency (type hint, instanceof, ::class,
     * static call, ...). Computed by the analyser when a usage-aware rule is
     * active; false otherwise.
     */
    public function setReferenced(bool $isReferenced): void
    {
        $this->isReferenced = $isReferenced;
    }

    /**
     * Whether another scanned scope instantiates this class — `new X`, or a
     * `new self`/`new static`/`new parent` resolving to it. Instantiation is
     * the one usage that requires a class to stay concrete. Computed by the
     * analyser when a usage-aware rule is active; false otherwise.
     *
     * Only a concrete named class can be an instantiation target — `new` on
     * an abstract class, interface, trait, or enum is fatal — so marking any
     * other class-like as instantiated is ignored. (Anonymous classes never
     * become ClassNodes in the first place.)
     */
    public function setInstantiated(bool $isInstantiated): void
    {
        if ($isInstantiated && (! $this->isClass() || $this->isAbstract)) {
            return;
        }

        $this->isInstantiated = $isInstantiated;
    }

    public function shortName(): string
    {
        $position = strrpos($this->className, '\\');

        return $position === false
            ? $this->className
            : substr($this->className, $position + 1);
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

    /**
     * Classes and enums implement interfaces; interfaces extend them. Both
     * relations are matched here, directly or through any ancestor.
     */
    public function implementsInterface(string $interface): bool
    {
        return $this->matchesAnyClassLike($interface, $this->implements)
            || $this->matchesAnyClassLike($interface, $this->interfaceExtends)
            || $this->matchesAnyClassLike($interface, $this->parentInterfaces);
    }

    public function extendsClass(string $class): bool
    {
        if ($this->extends !== null && strcasecmp($this->extends, $class) === 0) {
            return true;
        }

        return $this->matchesAnyClassLike($class, $this->parentClasses);
    }

    /**
     * Class-like names are case-insensitive in PHP. This matching is kept
     * separate from dependencies, which may also contain constants.
     *
     * @param string[] $classLikes
     */
    private function matchesAnyClassLike(string $needle, array $classLikes): bool
    {
        foreach ($classLikes as $classLike) {
            if (strcasecmp($classLike, $needle) === 0) {
                return true;
            }
        }

        return false;
    }

    public function callsFunction(string $function): bool
    {
        foreach ($this->functionCalls as $functionCall) {
            if (strcasecmp($functionCall, $function) === 0) {
                return true;
            }
        }

        return false;
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
            if ($method->isConstructor()) {
                return $method->paramCount;
            }
        }

        return 0;
    }
}
