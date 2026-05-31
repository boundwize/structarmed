<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

interface LayerAwareRuleInterface
{
    /**
     * @param array<string, string> $classLayerMap className → layer name
     */
    public function injectClassLayerMap(array $classLayerMap): void;
}
