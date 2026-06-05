<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule;

interface LayerAwareRuleInterface
{
    /**
     * @param array<string, string|list<string>> $classLayerMap className → layer name(s)
     */
    public function injectClassLayerMap(array $classLayerMap): void;
}
