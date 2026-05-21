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
        return $this->resolveMatches($className, $filePath)['matchedLayer'];
    }

    public function resolveAll(string $className, string $filePath): array
    {
        return $this->resolveMatches($className, $filePath)['matchedLayers'];
    }

    /**
     * @return array{matchedLayer: string|null, matchedLayers: list<string>}
     */
    private function resolveMatches(string $className, string $filePath): array
    {
        $key     = $className . "\0" . $filePath;
        $matches = $this->chainLayerResolverCache->get($key);

        if ($matches !== null) {
            return $matches;
        }

        $matchedLayer  = null;
        $matchedLayers = [];

        foreach ($this->resolvers as $resolver) {
            if ($matchedLayer === null) {
                $layer = $resolver->resolve($className, $filePath);

                if ($layer !== null) {
                    $matchedLayer = $layer;
                }
            }

            foreach ($resolver->resolveAll($className, $filePath) as $layer) {
                if (! in_array($layer, $matchedLayers, true)) {
                    $matchedLayers[] = $layer;
                }
            }
        }

        $matches = [
            'matchedLayer'  => $matchedLayer,
            'matchedLayers' => $matchedLayers,
        ];

        $this->chainLayerResolverCache->set($key, $matches);

        return $matches;
    }
}
