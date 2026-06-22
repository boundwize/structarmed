<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

use Boundwize\StructArmed\Analyser\ClassNode;

interface LayerAwareRuleInterface
{
    /** @param array<string, ClassNode> $classNodeMap class name → class node */
    public function injectClassNodeMap(array $classNodeMap): void;
}
