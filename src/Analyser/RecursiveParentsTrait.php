<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function strcasecmp;

/**
 * Parent-chain queries shared by {@see ClassNode} and {@see AnonymousClassNode}.
 * Both carry the class they extend and the interfaces they implement directly,
 * and receive their transitive parents from the analyser once every scanned
 * class-like is known.
 *
 * @internal
 *
 * @property-read string|null $extends          Parent class name, null when none
 * @property-read string[]    $implements       Interface names implemented directly
 * @property list<string>     $parentClasses    Direct and transitive parent class names
 * @property list<string>     $parentInterfaces Direct and transitive implemented or extended interface names
 */
trait RecursiveParentsTrait
{
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
     * Implemented directly, or via any parent class or interface.
     */
    public function implementsInterface(string $interface): bool
    {
        return $this->matchesAnyClassLike($interface, $this->implements)
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
}
