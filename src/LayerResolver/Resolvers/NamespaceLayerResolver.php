<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;

use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
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
    /**
     * @param array<string, string|list<string>> $layers  Map of layer name → path prefixes
     */
    public function __construct(
        private array $layers,
        private string $basePath,
    ) {
    }

    public function resolve(string $className, string $filePath): ?string
    {
        $normalised = $this->normalisePath($filePath);

        foreach ($this->layers as $layerName => $layerPaths) {
            foreach ((array) $layerPaths as $layerPath) {
                $normalisedLayer = $this->normalisePath(
                    $this->basePath . DIRECTORY_SEPARATOR . trim($layerPath, '/')
                );

                if (str_starts_with($normalised, $normalisedLayer)) {
                    return $layerName;
                }
            }
        }

        return null;
    }

    private function normalisePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
    }
}
