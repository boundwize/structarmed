<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Util\Path;

use function str_starts_with;
use function strlen;
use function usort;

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
     * Layer paths stored with a trailing '/' so a single str_starts_with()
     * against the file path (also suffixed with '/') covers both exact and
     * descendant matches. Kept in declaration order for resolveAll().
     *
     * @var array<string, list<string>>
     */
    private array $normalisedLayers;

    /**
     * The same paths flattened and sorted longest first, so resolve() can
     * return on the first match: it is always the most specific layer.
     * Equal lengths keep declaration order.
     *
     * @var list<array{0: string, 1: string}> [layerPath, layerName]
     */
    private array $layerPathsLongestFirst;

    /**
     * @param array<string, string|list<string>> $layers  Map of layer name → path prefixes
     */
    public function __construct(
        array $layers,
        string $basePath,
    ) {
        $normalisedLayers       = [];
        $layerPathsLongestFirst = [];

        foreach ($layers as $layerName => $layerPaths) {
            foreach ((array) $layerPaths as $layerPath) {
                $normalisedPath = Path::normalise(Path::resolve($layerPath, $basePath), canonicalise: true) . '/';

                $normalisedLayers[$layerName][] = $normalisedPath;
                $layerPathsLongestFirst[]       = [$normalisedPath, $layerName];
            }
        }

        usort($layerPathsLongestFirst, static fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        $this->normalisedLayers       = $normalisedLayers;
        $this->layerPathsLongestFirst = $layerPathsLongestFirst;
    }

    public function resolve(string $className, string $filePath): ?string
    {
        $pathWithSlash = Path::normalise($filePath, canonicalise: true) . '/';

        foreach ($this->layerPathsLongestFirst as [$layerPath, $layerName]) {
            if (str_starts_with($pathWithSlash, $layerPath)) {
                return $layerName;
            }
        }

        return null;
    }

    /**
     * @return int[]|string[]
     */
    public function resolveAll(string $className, string $filePath): array
    {
        $pathWithSlash = Path::normalise($filePath, canonicalise: true) . '/';
        $matched       = [];

        foreach ($this->normalisedLayers as $layerName => $layerPaths) {
            foreach ($layerPaths as $layerPath) {
                if (str_starts_with($pathWithSlash, $layerPath)) {
                    $matched[] = $layerName;
                    break;
                }
            }
        }

        return $matched;
    }
}
