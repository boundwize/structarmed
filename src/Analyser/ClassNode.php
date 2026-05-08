<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

final class ClassNode
{
    /**
     * @param string[]      $dependencies   Fully-qualified class names this class depends on
     * @param string[]      $implements     Interface names this class implements
     * @param MethodNode[]  $methods        Public methods of this class
     * @param string[]      $functionCalls  Global functions called within this class
     * @param string[]      $superglobals   Superglobals accessed ($_GET, $_POST, etc.)
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
        public readonly array $dependencies = [],
        public readonly array $implements = [],
        public readonly array $methods = [],
        public readonly array $functionCalls = [],
        public readonly array $superglobals = [],
    ) {}

    public function shortName(): string
    {
        $parts = explode('\\', $this->className);

        return end($parts);
    }

    public function isInLayer(string $layer): bool
    {
        return $this->layer === $layer;
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
        foreach ($this->dependencies as $dependency) {
            if (str_starts_with($dependency, $classOrNamespace)) {
                return true;
            }
        }

        return false;
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
