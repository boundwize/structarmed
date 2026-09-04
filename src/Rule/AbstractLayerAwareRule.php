<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\ClassNode;

/**
 * A rule that needs the layer of a class other than the one under evaluation,
 * such as the layer a dependency belongs to. The analyser injects the scanned
 * class node map before any class is evaluated.
 */
abstract class AbstractLayerAwareRule
{
    /** @var array<string, ClassNode> class name → class node */
    protected array $classNodeMap = [];

    /** @param array<string, ClassNode> $classNodeMap */
    public function injectClassNodeMap(array $classNodeMap): void
    {
        $this->classNodeMap = $classNodeMap;
    }
}
