<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;

/**
 * Resolves a layer by matching the class short name against regex patterns.
 *
 * Useful as a fallback for codebases where namespaces do not reflect
 * architecture layers.
 *
 * Example:
 *   'Domain' → '/Entity$|ValueObject$|AggregateRoot$/'
 */
final class PatternLayerResolver implements LayerResolverInterface
{
    /**
     * @param array<string, string> $patterns  Map of layer name → regex pattern
     */
    public function __construct(
        private readonly array $patterns
    ) {}

    public function resolve(string $className, string $filePath): ?string
    {
        $shortName = $this->shortName($className);

        foreach ($this->patterns as $layerName => $pattern) {
            if ((bool) preg_match($pattern, $shortName)) {
                return $layerName;
            }
        }

        return null;
    }

    private function shortName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }
}
