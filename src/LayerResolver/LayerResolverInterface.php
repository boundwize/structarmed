<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver;

interface LayerResolverInterface
{
    /**
     * Resolve the layer name for a given class.
     * Returns null if the class does not belong to any known layer.
     */
    public function resolve(string $className, string $filePath): ?string;
}
