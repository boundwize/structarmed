<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function array_filter;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function strcasecmp;
use function strrpos;
use function substr;

final class ClassNode
{
    use NodeQueryTrait;

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
     */
    public function setInstantiated(bool $isInstantiated): void
    {
        $this->isInstantiated = $isInstantiated;
    }

    public function shortName(): string
    {
        $position = strrpos($this->className, '\\');

        return $position === false
            ? $this->className
            : substr($this->className, $position + 1);
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

    /**
     * Implemented directly, extended directly (for interfaces), or via any parent class or interface.
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
