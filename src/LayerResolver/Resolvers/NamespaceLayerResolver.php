<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver\Resolvers;

use Boundwize\StructArmed\LayerResolver\LayerResolverInterface;
use Boundwize\StructArmed\Util\Path;

use function in_array;
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
     * descendant matches. Longest path first, so the first match is the most
     * specific layer; equal lengths keep declaration order.
     *
     * @var list<array{0: string, 1: string}> [layerPath, layerName]
     */
    private array $layerPaths;

    /**
     * @param array<string, string|list<string>> $layers  Map of layer name → path prefixes
     */
    public function __construct(
        array $layers,
        string $basePath,
    ) {
        $layerPaths = [];

        foreach ($layers as $layerName => $paths) {
            foreach ((array) $paths as $path) {
                $layerPath    = Path::normalise(Path::resolve($path, $basePath), canonicalise: true) . '/';
                $layerPaths[] = [$layerPath, $layerName];
            }
        }

        usort($layerPaths, static fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        $this->layerPaths = $layerPaths;
    }

    public function resolve(string $className, string $filePath): ?string
    {
        $pathWithSlash = Path::normalise($filePath, canonicalise: true) . '/';

        foreach ($this->layerPaths as [$layerPath, $layerName]) {
            if (str_starts_with($pathWithSlash, $layerPath)) {
                return $layerName;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function resolveAll(string $className, string $filePath): array
    {
        $pathWithSlash = Path::normalise($filePath, canonicalise: true) . '/';
        $matched       = [];

        foreach ($this->layerPaths as [$layerPath, $layerName]) {
            if (str_starts_with($pathWithSlash, $layerPath) && ! in_array($layerName, $matched, true)) {
                $matched[] = $layerName;
            }
        }

        return $matched;
    }
}
