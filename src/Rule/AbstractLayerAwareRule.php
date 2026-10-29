<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\ClassNode;

use function array_change_key_case;
use function strtolower;

/**
 * A rule that needs the layer of a class other than the one under evaluation,
 * such as the layer a dependency belongs to. The analyser injects the scanned
 * class node map before any class is evaluated.
 */
abstract class AbstractLayerAwareRule
{
    /** @var array<string, ClassNode> class name → class node */
    protected array $classNodeMap = [];

    /** @var array<string, ClassNode> lowercased class name → class node */
    private array $lowercaseClassNodeMap = [];

    /** @param array<string, ClassNode> $classNodeMap */
    public function injectClassNodeMap(array $classNodeMap): void
    {
        $this->classNodeMap          = $classNodeMap;
        $this->lowercaseClassNodeMap = array_change_key_case($classNodeMap);
    }

    /**
     * The scanned node of a dependency, or null when it lies outside the
     * scanned paths. Class names are case-insensitive, so `extends internalbase`
     * still finds the node declared as InternalBase.
     */
    protected function getDependencyNode(string $dependency): ?ClassNode
    {
        return $this->classNodeMap[$dependency]
            ?? $this->lowercaseClassNodeMap[strtolower($dependency)]
            ?? null;
    }
}
