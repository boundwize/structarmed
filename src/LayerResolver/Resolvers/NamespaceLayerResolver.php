<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;

use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * Resolves a layer by matching the file path against registered layer paths.
 *
 * Example:
 *   'Domain' → 'src/Domain/'
 *   A file at 'src/Domain/Entities/Order.php' resolves to 'Domain'
 */
final readonly class NamespaceLayerResolver implements LayerResolverInterface
{
    /** @var array<string, list<string>> */
    private array $normalisedLayers;

    private NamespaceLayerResolverCache $matchedLayersByPath;

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
                $normalisedLayers[$layerName][] = $this->normalisePath(
                    $basePath . DIRECTORY_SEPARATOR . trim($layerPath, '/')
                );
            }
        }

        $this->normalisedLayers    = $normalisedLayers;
        $this->matchedLayersByPath = new NamespaceLayerResolverCache();
    }

    public function resolve(string $className, string $filePath): ?string
    {
        return $this->resolveMatches($filePath)['matchedLayer'];
    }

    /**
     * @return list<string>
     */
    public function resolveAll(string $className, string $filePath): array
    {
        return $this->resolveMatches($filePath)['matchedLayers'];
    }

    /**
     * @return array{matchedLayer: string|null, matchedLayers: list<string>}
     */
    private function resolveMatches(string $filePath): array
    {
        $normalised = $this->normalisePath($filePath);

        $matches = $this->matchedLayersByPath->get($normalised);

        if ($matches !== null) {
            return $matches;
        }

        $matchedLayer  = null;
        $matchedLayers = [];
        $matchedLength = -1;

        foreach ($this->normalisedLayers as $layerName => $layerPaths) {
            $layerMatched = false;

            foreach ($layerPaths as $layerPath) {
                if ($this->matchesLayerPath($normalised, $layerPath)) {
                    if (! $layerMatched) {
                        $matchedLayers[] = $layerName;
                        $layerMatched    = true;
                    }

                    $length = strlen($layerPath);

                    if ($length > $matchedLength) {
                        $matchedLayer  = $layerName;
                        $matchedLength = $length;
                    }
                }
            }
        }

        $matches = [
            'matchedLayer'  => $matchedLayer,
            'matchedLayers' => $matchedLayers,
        ];

        $this->matchedLayersByPath->set($normalised, $matches);

        return $matches;
    }

    private function normalisePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
    }

    private function matchesLayerPath(string $path, string $layerPath): bool
    {
        return $path === $layerPath || str_starts_with($path, $layerPath . '/');
    }
}
