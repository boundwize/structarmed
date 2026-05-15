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
final class NamespaceLayerResolver implements LayerResolverInterface
{
    /**
     * Normalised layer paths keyed by layer name, with their string lengths pre-computed.
     * Shape: array<string, list<array{path: string, length: int}>>
     *
     * @var array<string, list<array{path: string, length: int}>>
     */
    private array $normalisedLayerPaths = [];

    /**
     * @param array<string, string|list<string>> $layers  Map of layer name → path prefixes
     */
    public function __construct(
        array $layers,
        string $basePath,
    ) {
        foreach ($layers as $layerName => $layerPaths) {
            foreach ((array) $layerPaths as $layerPath) {
                $path = $this->normalisePath(
                    $basePath . DIRECTORY_SEPARATOR . trim($layerPath, '/')
                );

                $this->normalisedLayerPaths[$layerName][] = [
                    'path'   => $path,
                    'length' => strlen($path),
                ];
            }
        }
    }

    public function resolve(string $className, string $filePath): ?string
    {
        $normalised    = $this->normalisePath($filePath);
        $matchedLayer  = null;
        $matchedLength = -1;

        foreach ($this->normalisedLayerPaths as $layerName => $paths) {
            foreach ($paths as ['path' => $normalisedLayer, 'length' => $length]) {
                if ($length > $matchedLength && str_starts_with($normalised, $normalisedLayer)) {
                    $matchedLayer  = $layerName;
                    $matchedLength = $length;
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
        $normalised = $this->normalisePath($filePath);
        $matched    = [];

        foreach ($this->normalisedLayerPaths as $layerName => $paths) {
            foreach ($paths as ['path' => $normalisedLayer]) {
                if (str_starts_with($normalised, $normalisedLayer)) {
                    $matched[] = $layerName;
                    break;
                }
            }
        }

        return $matched;
    }

    private function normalisePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
    }
}
