<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Util\Path;

use function str_starts_with;
use function strlen;

/**
 * Resolves a layer by matching the file path against registered layer paths.
 *
 * Example:
 *   'Domain' → 'src/Domain/'
 *   A file at 'src/Domain/Entities/Order.php' resolves to 'Domain'
 */
final readonly class NamespaceLayerResolver implements LayerResolverInterface
{
    /**
     * Path, its directory prefix and length are precomputed once so the
     * class × layer × path hot loop in resolve()/resolveAll() is pure comparisons.
     *
     * @var array<string, list<array{path: string, prefix: string, length: int}>>
     */
    private array $normalisedLayers;

    /**
     * @param array<string, string|list<string>> $layers  Map of layer name → path prefixes
     */
    public function __construct(
        array $layers,
        string $basePath,
    ) {
        $normalisedLayers = [];

        foreach ($layers as $layerName => $layerPaths) {
            foreach ((array) $layerPaths as $layerPath) {
                $path = Path::normalise(Path::resolve($layerPath, $basePath), canonicalise: true);

                $normalisedLayers[$layerName][] = [
                    'path'   => $path,
                    'prefix' => $path . '/',
                    'length' => strlen($path),
                ];
            }
        }

        $this->normalisedLayers = $normalisedLayers;
    }

    public function resolve(string $className, string $filePath): ?string
    {
        $normalised    = Path::normalise($filePath, canonicalise: true);
        $matchedLayer  = null;
        $matchedLength = -1;

        foreach ($this->normalisedLayers as $layerName => $layerPaths) {
            foreach ($layerPaths as $layerPath) {
                if ($layerPath['length'] <= $matchedLength) {
                    continue;
                }

                if ($normalised !== $layerPath['path'] && ! str_starts_with($normalised, $layerPath['prefix'])) {
                    continue;
                }

                $matchedLayer  = $layerName;
                $matchedLength = $layerPath['length'];
            }
        }

        return $matchedLayer;
    }

    /**
     * @return int[]|string[]
     */
    public function resolveAll(string $className, string $filePath): array
    {
        $normalised = Path::normalise($filePath, canonicalise: true);
        $matched    = [];

        foreach ($this->normalisedLayers as $layerName => $layerPaths) {
            foreach ($layerPaths as $layerPath) {
                if ($normalised !== $layerPath['path'] && ! str_starts_with($normalised, $layerPath['prefix'])) {
                    continue;
                }

                $matched[] = $layerName;
                break;
            }
        }

        return $matched;
    }
}
