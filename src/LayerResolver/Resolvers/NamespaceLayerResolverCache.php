<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

use function array_key_exists;

/**
 * @internal
 */
final class NamespaceLayerResolverCache
{
    /** @var array<string, string|null> */
    private array $matchedLayerByPath = [];

    /** @var array<string, list<string>> */
    private array $matchedLayersByPath = [];

    public function hasMatchedLayer(string $path): bool
    {
        return array_key_exists($path, $this->matchedLayerByPath);
    }

    public function getMatchedLayer(string $path): ?string
    {
        return $this->matchedLayerByPath[$path] ?? null;
    }

    public function setMatchedLayer(string $path, ?string $matchedLayer): void
    {
        $this->matchedLayerByPath[$path] = $matchedLayer;
    }

    public function hasMatchedLayers(string $path): bool
    {
        return array_key_exists($path, $this->matchedLayersByPath);
    }

    /**
     * @return list<string>
     */
    public function getMatchedLayers(string $path): array
    {
        return $this->matchedLayersByPath[$path] ?? [];
    }

    /**
     * @param list<string> $matchedLayers
     */
    public function setMatchedLayers(string $path, array $matchedLayers): void
    {
        $this->matchedLayersByPath[$path] = $matchedLayers;
    }
}
