<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\LayerResolver;

final readonly class ChainLayerResolver implements LayerResolverInterface
{
    /** @var LayerResolverInterface[] */
    private array $resolvers;

    public function __construct(LayerResolverInterface ...$resolvers)
    {
        $this->resolvers = $resolvers;
    }

    public function resolve(string $className, string $filePath): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $layer = $resolver->resolve($className, $filePath);

            if ($layer !== null) {
                return $layer;
            }
        }

        return null;
    }
}
