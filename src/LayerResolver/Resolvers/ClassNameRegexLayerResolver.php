<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;

use function preg_match;

/**
 * Resolves a layer by matching the fully-qualified class name against a regex pattern.
 *
 * Useful for codebases where architecture layers are expressed through namespaces
 * (or namespace sub-trees) rather than file-system paths.
 *
 * Supports an optional exclude pattern which prevents a class from being assigned
 * to the layer even when the primary pattern matches.
 *
 * Example:
 *   'HTTP' → pattern '/^App\\HTTP\\.*$/', excludePattern '/(Exception|URI)/'
 *   A class 'App\HTTP\Request' resolves to 'HTTP'.
 *   A class 'App\HTTP\URI'     does NOT resolve to 'HTTP' (excluded).
 */
final readonly class ClassNameRegexLayerResolver implements LayerResolverInterface
{
    /**
     * @param array<string, array{pattern: string, excludePattern: string|null}> $layerPatterns
     *        Map of layer name → pattern config.
     */
    public function __construct(private array $layerPatterns)
    {
    }

    public function resolve(string $className, string $filePath): ?string
    {
        foreach ($this->layerPatterns as $layerName => $config) {
            if (! (bool) preg_match($config['pattern'], $className)) {
                continue;
            }

            if ($config['excludePattern'] !== null && (bool) preg_match($config['excludePattern'], $className)) {
                continue;
            }

            return $layerName;
        }

        return null;
    }

    /**
     * @return int[]|string[]
     */
    public function resolveAll(string $className, string $filePath): array
    {
        $matched = [];

        foreach ($this->layerPatterns as $layerName => $config) {
            if (! (bool) preg_match($config['pattern'], $className)) {
                continue;
            }

            if ($config['excludePattern'] !== null && (bool) preg_match($config['excludePattern'], $className)) {
                continue;
            }

            $matched[] = $layerName;
        }

        return $matched;
    }
}
