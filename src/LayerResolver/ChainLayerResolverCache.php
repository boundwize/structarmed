<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver;

use function array_key_exists;

/**
 * @internal
 */
final class ChainLayerResolverCache
{
    /** @var array<string, string|null> */
    private array $layerByKey = [];

    /** @var array<string, list<string>> */
    private array $layersByKey = [];

    public function getLayer(string $key): string|false|null
    {
        return array_key_exists($key, $this->layerByKey) ? $this->layerByKey[$key] : false;
    }

    public function setLayer(string $key, ?string $layer): void
    {
        $this->layerByKey[$key] = $layer;
    }

    /**
     * @return list<string>|null  null = cache miss
     */
    public function getLayers(string $key): ?array
    {
        return $this->layersByKey[$key] ?? null;
    }

    /**
     * @param list<string> $layers
     */
    public function setLayers(string $key, array $layers): void
    {
        $this->layersByKey[$key] = $layers;
    }
}
