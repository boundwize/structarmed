<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver;

use Boundwize\StructArmed\LayerResolver\Resolvers\ClassNameRegexLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;

use function array_key_exists;
use function in_array;

final class ChainLayerResolver implements LayerResolverInterface
{
    /** @var LayerResolverInterface[] */
    private readonly array $resolvers;

    /** @var array<string, string|null> */
    private array $resolveCachedLayer = [];

    /** @var array<string, list<string>> */
    private array $resolveAllCachedLayers = [];

    public function __construct(LayerResolverInterface ...$resolvers)
    {
        $this->resolvers = $resolvers;
    }

    /**
     * @param array<string, string|list<string>> $layers
     * @param array<string, array{pattern: string, excludePattern: string|null}> $layerPatterns
     */
    public static function fromLayerConfig(array $layers, string $basePath, array $layerPatterns = []): self
    {
        return $layerPatterns !== []
            ? new self(
                new ClassNameRegexLayerResolver($layerPatterns),
                new NamespaceLayerResolver($layers, $basePath)
            )
            : new self(
                new NamespaceLayerResolver($layers, $basePath)
            );
    }

    public function resolve(string $className, string $filePath): ?string
    {
        $key = $className . "\0" . $filePath;

        if (array_key_exists($key, $this->resolveCachedLayer)) {
            return $this->resolveCachedLayer[$key];
        }

        foreach ($this->resolvers as $resolver) {
            $layer = $resolver->resolve($className, $filePath);

            if ($layer !== null) {
                return $this->resolveCachedLayer[$key] = $layer;
            }
        }

        return $this->resolveCachedLayer[$key] = null;
    }

    public function resolveAll(string $className, string $filePath): array
    {
        $key = $className . "\0" . $filePath;

        if (array_key_exists($key, $this->resolveAllCachedLayers)) {
            return $this->resolveAllCachedLayers[$key];
        }

        $matchedLayers = [];

        foreach ($this->resolvers as $resolver) {
            foreach ($resolver->resolveAll($className, $filePath) as $singleLayer) {
                if (! in_array($singleLayer, $matchedLayers, true)) {
                    $matchedLayers[] = $singleLayer;
                }
            }
        }

        return $this->resolveAllCachedLayers[$key] = $matchedLayers;
    }
}
