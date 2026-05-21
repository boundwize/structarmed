<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver;

use Boundwize\StructArmed\LayerResolver\Resolvers\ClassNameRegexLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;

use function in_array;

final readonly class ChainLayerResolver implements LayerResolverInterface
{
    /** @var LayerResolverInterface[] */
    private array $resolvers;

    private ChainLayerResolverCache $chainLayerResolverCache;

    public function __construct(LayerResolverInterface ...$resolvers)
    {
        $this->resolvers               = $resolvers;
        $this->chainLayerResolverCache = new ChainLayerResolverCache();
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
        $key    = $className . "\0" . $filePath;
        $cached = $this->chainLayerResolverCache->getLayer($key);

        if ($cached !== false) {
            return $cached;
        }

        foreach ($this->resolvers as $resolver) {
            $layer = $resolver->resolve($className, $filePath);

            if ($layer !== null) {
                $this->chainLayerResolverCache->setLayer($key, $layer);

                return $layer;
            }
        }

        $this->chainLayerResolverCache->setLayer($key, null);

        return null;
    }

    public function resolveAll(string $className, string $filePath): array
    {
        $key    = $className . "\0" . $filePath;
        $cached = $this->chainLayerResolverCache->getLayers($key);

        if ($cached !== null) {
            return $cached;
        }

        $layers = [];

        foreach ($this->resolvers as $resolver) {
            foreach ($resolver->resolveAll($className, $filePath) as $layer) {
                if (! in_array($layer, $layers, true)) {
                    $layers[] = $layer;
                }
            }
        }

        $this->chainLayerResolverCache->setLayers($key, $layers);

        return $layers;
    }
}
