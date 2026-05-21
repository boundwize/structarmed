<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

/**
 * @internal
 */
final class NamespaceLayerResolverCache
{
    /** @var array<string, array{matchedLayer: string|null, matchedLayers: list<string>}> */
    private array $matchesByPath = [];

    /**
     * @return array{matchedLayer: string|null, matchedLayers: list<string>}|null
     */
    public function get(string $path): ?array
    {
        return $this->matchesByPath[$path] ?? null;
    }

    /**
     * @param array{matchedLayer: string|null, matchedLayers: list<string>} $matches
     */
    public function set(string $path, array $matches): void
    {
        $this->matchesByPath[$path] = $matches;
    }
}
