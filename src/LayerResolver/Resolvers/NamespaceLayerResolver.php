<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Util\Path;
use Boundwize\StructArmed\Util\SourceLayerName;

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
     * Layer paths stored with a trailing '/' so a single str_starts_with()
     * against the file path (also suffixed with '/') covers both exact and
     * descendant matches.
     *
     * @var array<string, list<string>>
     */
    private array $normalisedLayers;

    /**
     * Path prefixes carved out of a layer, normalised like $normalisedLayers.
     *
     * @var array<string, list<string>>
     */
    private array $normalisedLayerExcludePaths;

    /**
     * @param array<string, string|list<string>> $layers  Map of layer name → path prefixes
     * @param array<string, string|list<string>> $layerExcludePaths  Map of layer name → excluded path prefixes
     */
    public function __construct(
        array $layers,
        string $basePath,
        array $layerExcludePaths = [],
    ) {
        $this->normalisedLayers            = $this->normalisePaths($layers, $basePath);
        $this->normalisedLayerExcludePaths = $this->normalisePaths($layerExcludePaths, $basePath);
    }

    public function resolve(string $className, string $filePath): ?string
    {
        $pathWithSlash = Path::normalise($filePath, canonicalise: true) . '/';
        $matchedLayer  = null;
        $matchedLength = -1;

        foreach ($this->normalisedLayers as $layerName => $layerPaths) {
            if ($this->isExcluded($layerName, $pathWithSlash)) {
                continue;
            }

            foreach ($layerPaths as $layerPath) {
                if (str_starts_with($pathWithSlash, $layerPath)) {
                    $length = strlen($layerPath);

                    // A Source layer represents preset source scope: on an equally
                    // specific match it yields to an architectural layer, regardless
                    // of registration order.
                    $isSourceTie = $length === $matchedLength
                        && SourceLayerName::matches((string) $matchedLayer)
                        && ! SourceLayerName::matches((string) $layerName);

                    if ($length > $matchedLength || $isSourceTie) {
                        $matchedLayer  = $layerName;
                        $matchedLength = $length;
                    }
                }
            }
        }

        return $matchedLayer;
    }

    /**
     * @return int[]|string[]
     */
    public function resolveAll(string $className, string $filePath): array
    {
        $pathWithSlash = Path::normalise($filePath, canonicalise: true) . '/';
        $matched       = [];

        foreach ($this->normalisedLayers as $layerName => $layerPaths) {
            if ($this->isExcluded($layerName, $pathWithSlash)) {
                continue;
            }

            foreach ($layerPaths as $layerPath) {
                if (str_starts_with($pathWithSlash, $layerPath)) {
                    $matched[] = $layerName;
                    break;
                }
            }
        }

        return $matched;
    }

    private function isExcluded(int|string $layerName, string $pathWithSlash): bool
    {
        foreach ($this->normalisedLayerExcludePaths[$layerName] ?? [] as $excludePath) {
            if (str_starts_with($pathWithSlash, $excludePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string|list<string>> $paths
     * @return array<string, list<string>>
     */
    private function normalisePaths(array $paths, string $basePath): array
    {
        $normalisedPaths = [];

        foreach ($paths as $layerName => $layerPaths) {
            foreach ((array) $layerPaths as $layerPath) {
                $normalisedPaths[$layerName][] = Path::normalise(
                    Path::resolve($layerPath, $basePath),
                    canonicalise: true
                ) . '/';
            }
        }

        return $normalisedPaths;
    }
}
