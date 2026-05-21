<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver;

/**
 * @internal
 */
final class ChainLayerResolverCache
{
    /** @var array<string, array{matchedLayer: string|null, matchedLayers: list<string>}> */
    private array $matchesByKey = [];

    /**
     * @return array{matchedLayer: string|null, matchedLayers: list<string>}|null
     */
    public function get(string $key): ?array
    {
        return $this->matchesByKey[$key] ?? null;
    }

    /**
     * @param array{matchedLayer: string|null, matchedLayers: list<string>} $matches
     */
    public function set(string $key, array $matches): void
    {
        $this->matchesByKey[$key] = $matches;
    }
}
