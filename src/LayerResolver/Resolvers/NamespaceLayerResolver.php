<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Util\Path;

use function array_key_exists;
use function str_starts_with;
use function strlen;

/**
 * Resolves a layer by matching the file path against registered layer paths.
 *
 * Example:
 *   'Domain' → 'src/Domain/'
 *   A file at 'src/Domain/Entities/Order.php' resolves to 'Domain'
 */
final class NamespaceLayerResolver implements LayerResolverInterface
{
    /** @var array<string, string|null> */
    private array $resolvedLayers = [];

    /** @var array<string, list<string>> */
    private array $resolvedAllLayers = [];

    /**
     * Layer paths stored with a trailing '/' so a single str_starts_with()
     * against the file path (also suffixed with '/') covers both exact and
     * descendant matches.
     *
     * @var array<string, list<string>>
     */
    private readonly array $normalisedLayers;

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
                $normalisedLayers[$layerName][] = Path::normalise(
                    Path::resolve($layerPath, $basePath),
                    canonicalise: true
                ) . '/';
            }
        }

        $this->normalisedLayers = $normalisedLayers;
    }

    public function resolve(string $className, string $filePath): ?string
    {
        $path = Path::normalise($filePath, canonicalise: true);

        if (array_key_exists($path, $this->resolvedLayers)) {
            return $this->resolvedLayers[$path];
        }

        $pathWithSlash = $path . '/';
        $matchedLayer  = null;
        $matchedLength = -1;

        foreach ($this->normalisedLayers as $layerName => $layerPaths) {
            foreach ($layerPaths as $layerPath) {
                if (str_starts_with($pathWithSlash, $layerPath)) {
                    $length = strlen($layerPath);

                    if ($length > $matchedLength) {
                        $matchedLayer  = $layerName;
                        $matchedLength = $length;
                    }
                }
            }
        }

        return $this->resolvedLayers[$path] = $matchedLayer;
    }

    /**
     * @return int[]|string[]
     */
    public function resolveAll(string $className, string $filePath): array
    {
        $path = Path::normalise($filePath, canonicalise: true);

        if (isset($this->resolvedAllLayers[$path])) {
            return $this->resolvedAllLayers[$path];
        }

        $pathWithSlash = $path . '/';
        $matched       = [];

        foreach ($this->normalisedLayers as $layerName => $layerPaths) {
            foreach ($layerPaths as $layerPath) {
                if (str_starts_with($pathWithSlash, $layerPath)) {
                    $matched[] = $layerName;
                    break;
                }
            }
        }

        return $this->resolvedAllLayers[$path] = $matched;
    }
}
