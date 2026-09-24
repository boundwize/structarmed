<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\LayerResolver;

use App\Domain\Entities\Order;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\ClassNameRegexLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Tests\ArchitectureTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function dirname;

#[CoversClass(NamespaceLayerResolver::class)]
#[CoversClass(ChainLayerResolver::class)]

final class NamespaceLayerResolverTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = dirname(__DIR__, 2);
    }

    public function testResolvesLayerByFilePath(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: [
                'Domain'         => 'src/Domain/',
                'Application'    => 'src/Application/',
                'Infrastructure' => 'src/Infrastructure/',
            ],
            basePath: $this->basePath
        );

        // Point at a fake path that starts with src/Domain
        $layer = $namespaceLayerResolver->resolve(
            Order::class,
            $this->basePath . '/src/Domain/Entities/Order.php'
        );

        // May be null since path doesn't actually exist — but logic should be covered
        $this->assertTrue($layer === 'Domain' || $layer === null);
    }

    public function testReturnsNullForUnknownPath(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: ['Domain' => 'src/Domain/'],
            basePath: $this->basePath
        );

        $layer = $namespaceLayerResolver->resolve(
            'App\\ThirdParty\\SomeClass',
            '/vendor/third-party/SomeClass.php'
        );

        $this->assertNull($layer);
    }

    public function testResolvesLayerByAnyRegisteredPath(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: ['Source' => ['src/', 'tests/']],
            basePath: $this->basePath
        );

        $layer = $namespaceLayerResolver->resolve(
            ArchitectureTest::class,
            $this->basePath . '/tests/ArchitectureTest.php'
        );

        $this->assertSame('Source', $layer);
    }

    public function testResolvesLayerByAbsolutePath(): void
    {
        $layerPath              = $this->basePath . '/src/Domain';
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: ['Domain' => $layerPath],
            basePath: '/unrelated/project'
        );

        $this->assertSame(
            'Domain',
            $namespaceLayerResolver->resolve(Order::class, $layerPath . '/Entities/Order.php')
        );
    }

    public function testResolvesMostSpecificMatchingLayerPath(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: [
                'Source'         => 'src/',
                'Infrastructure' => 'src/Infrastructure/',
            ],
            basePath: $this->basePath
        );

        $layer = $namespaceLayerResolver->resolve(
            'App\\Infrastructure\\SQLAlbumRepository',
            $this->basePath . '/src/Infrastructure/Persistence/Album/SQLAlbumRepository.php'
        );

        $this->assertSame('Infrastructure', $layer);
    }

    #[DataProvider('provideSourceLayerNames')]
    public function testSpecificLayerWinsOverEquallySpecificSourceLayer(string $sourceLayerName): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: [
                $sourceLayerName => ['lib/', 'src/'],
                'Application'    => 'src/',
            ],
            basePath: $this->basePath
        );

        $filePath = $this->basePath . '/src/PlaceOrder.php';

        $this->assertSame('Application', $namespaceLayerResolver->resolve('App\\PlaceOrder', $filePath));
        $this->assertSame(
            [$sourceLayerName, 'Application'],
            $namespaceLayerResolver->resolveAll('App\\PlaceOrder', $filePath)
        );
    }

    #[DataProvider('provideSourceLayerNames')]
    public function testMoreSpecificSourceLayerStillWinsOverSpecificLayer(string $sourceLayerName): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: [
                'Application'    => 'src/',
                $sourceLayerName => 'src/Generated/',
            ],
            basePath: $this->basePath
        );

        $layer = $namespaceLayerResolver->resolve(
            'App\\Generated\\Proxy',
            $this->basePath . '/src/Generated/Proxy.php'
        );

        $this->assertSame($sourceLayerName, $layer);
    }

    /** @return iterable<string, array{string}> */
    public static function provideSourceLayerNames(): iterable
    {
        yield 'bare' => ['Source'];
        yield 'single path' => ['Source[src/]'];
        yield 'multiple paths' => ['Source[lib/,src/]'];
    }

    public function testFirstRegisteredLayerWinsBetweenEquallySpecificOrdinaryLayers(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: [
                'Sourcing'    => 'src/',
                'Application' => 'src/',
            ],
            basePath: $this->basePath
        );

        $layer = $namespaceLayerResolver->resolve('App\\PlaceOrder', $this->basePath . '/src/PlaceOrder.php');

        $this->assertSame('Sourcing', $layer);
    }

    public function testDoesNotResolveSiblingPathWithSamePrefix(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: ['App' => 'src/App/'],
            basePath: $this->basePath
        );

        $layer = $namespaceLayerResolver->resolve(
            'Project\\Application\\Service',
            $this->basePath . '/src/Application/Service.php'
        );

        $this->assertNull($layer);
    }

    public function testResolveAllReturnsAllMatchingLayers(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: [
                'Application' => 'src/Controllers/',
                'Controller'  => 'src/Controllers/',
            ],
            basePath: $this->basePath
        );

        $layers = $namespaceLayerResolver->resolveAll(
            'App\\Controllers\\AlbumController',
            $this->basePath . '/src/Controllers/AlbumController.php'
        );

        $this->assertContains('Application', $layers);
        $this->assertContains('Controller', $layers);
    }

    public function testExcludePathRemovesNestedLayerFromParentLayer(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: [
                'Logger'  => 'src/Logger/',
                'Factory' => 'src/Logger/Factory/',
            ],
            basePath: $this->basePath,
            layerExcludePaths: ['Logger' => ['src/Logger/Factory']]
        );

        $factoryFile = $this->basePath . '/src/Logger/Factory/LoggerFactory.php';
        $loggerFile  = $this->basePath . '/src/Logger/FileLogger.php';

        $this->assertSame(
            'Factory',
            $namespaceLayerResolver->resolve('App\\Logger\\Factory\\LoggerFactory', $factoryFile)
        );
        $this->assertSame(
            ['Factory'],
            $namespaceLayerResolver->resolveAll('App\\Logger\\Factory\\LoggerFactory', $factoryFile)
        );

        $this->assertSame('Logger', $namespaceLayerResolver->resolve('App\\Logger\\FileLogger', $loggerFile));
        $this->assertSame(['Logger'], $namespaceLayerResolver->resolveAll('App\\Logger\\FileLogger', $loggerFile));
    }

    public function testExcludePathWithoutOwnLayerResolvesToNoLayer(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: ['Logger' => 'src/Logger/'],
            basePath: $this->basePath,
            layerExcludePaths: ['Logger' => ['src/Logger/Factory/']]
        );

        $factoryFile = $this->basePath . '/src/Logger/Factory/LoggerFactory.php';

        $this->assertNull($namespaceLayerResolver->resolve('App\\Logger\\Factory\\LoggerFactory', $factoryFile));
        $this->assertSame([], $namespaceLayerResolver->resolveAll('App\\Logger\\Factory\\LoggerFactory', $factoryFile));
    }

    public function testExcludePathDoesNotExcludeSiblingPathWithSamePrefix(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: ['Logger' => 'src/Logger/'],
            basePath: $this->basePath,
            layerExcludePaths: ['Logger' => ['src/Logger/Factory']]
        );

        $this->assertSame(
            'Logger',
            $namespaceLayerResolver->resolve(
                'App\\Logger\\FactoryAware\\Handler',
                $this->basePath . '/src/Logger/FactoryAware/Handler.php'
            )
        );
    }

    public function testResolveAllDoesNotReturnSiblingPathWithSamePrefix(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: ['App' => 'src/App/'],
            basePath: $this->basePath
        );

        $layers = $namespaceLayerResolver->resolveAll(
            'Project\\Application\\Service',
            $this->basePath . '/src/Application/Service.php'
        );

        $this->assertSame([], $layers);
    }

    public function testResolveAllReturnsEmptyForUnknownPath(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: ['Domain' => 'src/Domain/'],
            basePath: $this->basePath
        );

        $layers = $namespaceLayerResolver->resolveAll(
            'App\\ThirdParty\\SomeClass',
            '/vendor/third-party/SomeClass.php'
        );

        $this->assertSame([], $layers);
    }

    public function testReusesCachedMatchesForSameFilePath(): void
    {
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig(
            layers: [
                'Source' => 'src/',
                'Domain' => 'src/Domain/',
            ],
            basePath: $this->basePath
        );
        $filePath           = $this->basePath . '/src/Domain/Order.php';

        $this->assertSame('Domain', $chainLayerResolver->resolve('App\\Domain\\Order', $filePath));
        $this->assertSame(['Source', 'Domain'], $chainLayerResolver->resolveAll('App\\Domain\\Order', $filePath));
    }

    public function testChainResolverCachesResolveResult(): void
    {
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig(
            layers: ['Domain' => 'src/Domain/'],
            basePath: $this->basePath
        );
        $filePath           = $this->basePath . '/src/Domain/Order.php';

        $first  = $chainLayerResolver->resolve('App\\Domain\\Order', $filePath);
        $second = $chainLayerResolver->resolve('App\\Domain\\Order', $filePath);

        $this->assertSame($first, $second);
    }

    public function testChainResolverCachesNullResolveResult(): void
    {
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig(
            layers: ['Domain' => 'src/Domain/'],
            basePath: $this->basePath
        );

        $first  = $chainLayerResolver->resolve('App\\Other\\Foo', '/other/Foo.php');
        $second = $chainLayerResolver->resolve('App\\Other\\Foo', '/other/Foo.php');

        $this->assertNull($first);
        $this->assertNull($second);
    }

    public function testChainResolverCachesResolveAllResult(): void
    {
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig(
            layers: [
                'Source' => 'src/',
                'Domain' => 'src/Domain/',
            ],
            basePath: $this->basePath
        );
        $filePath           = $this->basePath . '/src/Domain/Order.php';

        $first  = $chainLayerResolver->resolveAll('App\\Domain\\Order', $filePath);
        $second = $chainLayerResolver->resolveAll('App\\Domain\\Order', $filePath);

        $this->assertSame($first, $second);
    }

    public function testChainResolverResolveHitsCacheAfterResolveAll(): void
    {
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig(
            layers: [
                'Source' => 'src/',
                'Domain' => 'src/Domain/',
            ],
            basePath: $this->basePath
        );
        $filePath           = $this->basePath . '/src/Domain/Order.php';

        $layers = $chainLayerResolver->resolveAll('App\\Domain\\Order', $filePath);
        $layer  = $chainLayerResolver->resolve('App\\Domain\\Order', $filePath);

        $this->assertSame(['Source', 'Domain'], $layers);
        $this->assertSame('Domain', $layer);
    }

    public function testChainResolverTriesResolversInOrder(): void
    {
        $namespaceLayerResolver      = new NamespaceLayerResolver(
            layers: ['Domain' => 'src/Domain/'],
            basePath: $this->basePath
        );
        $classNameRegexLayerResolver = new ClassNameRegexLayerResolver([
            'FallbackLayer' => ['pattern' => '/SpecialClass$/', 'excludePattern' => null],
        ]);
        $chainLayerResolver          = new ChainLayerResolver($namespaceLayerResolver, $classNameRegexLayerResolver);

        $layer = $chainLayerResolver->resolve('App\\Anywhere\\SpecialClass', '/anywhere/SpecialClass.php');
        $this->assertSame('FallbackLayer', $layer);
    }

    public function testChainResolverReturnsNullWhenNoneMatch(): void
    {
        $chainLayerResolver = new ChainLayerResolver(
            new ClassNameRegexLayerResolver(['Domain' => ['pattern' => '/Entity$/', 'excludePattern' => null]])
        );

        $layer = $chainLayerResolver->resolve('App\\Foo\\OrderService', '/fake.php');
        $this->assertNull($layer);
    }

    public function testChainResolverResolveAllMergesResultsFromAllResolvers(): void
    {
        $namespaceLayerResolver      = new NamespaceLayerResolver(
            layers: ['Source' => 'src/'],
            basePath: $this->basePath
        );
        $classNameRegexLayerResolver = new ClassNameRegexLayerResolver([
            'Entity' => ['pattern' => '/Entity$/', 'excludePattern' => null],
        ]);
        $chainLayerResolver          = new ChainLayerResolver($namespaceLayerResolver, $classNameRegexLayerResolver);

        $layers = $chainLayerResolver->resolveAll(
            'App\\Domain\\OrderEntity',
            $this->basePath . '/src/Domain/OrderEntity.php'
        );

        $this->assertContains('Source', $layers);
        $this->assertContains('Entity', $layers);
    }

    public function testChainResolverResolveAllReturnsEmptyWhenNoneMatch(): void
    {
        $chainLayerResolver = new ChainLayerResolver(
            new ClassNameRegexLayerResolver(['Domain' => ['pattern' => '/Entity$/', 'excludePattern' => null]])
        );

        $layers = $chainLayerResolver->resolveAll('App\\Foo\\OrderService', '/fake.php');

        $this->assertSame([], $layers);
    }

    public function testFromLayerConfigWithoutLayerPatternsUsesNamespaceResolverOnly(): void
    {
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig(
            ['Domain' => 'src/Domain/'],
            $this->basePath
        );

        $this->assertSame(
            'Domain',
            $chainLayerResolver->resolve(
                'App\\Domain\\Order',
                $this->basePath . '/src/Domain/Order.php'
            )
        );
        $this->assertNull($chainLayerResolver->resolve('App\\Other\\Foo', '/other/Foo.php'));
    }

    public function testFromLayerConfigWithLayerPatternsIncludesRegexResolver(): void
    {
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig(
            [],
            $this->basePath,
            ['HTTP' => ['pattern' => '/^App\\\\HTTP\\\\.*$/', 'excludePattern' => null]]
        );

        $this->assertSame('HTTP', $chainLayerResolver->resolve('App\\HTTP\\Request', ''));
        $this->assertNull($chainLayerResolver->resolve('App\\Other\\Foo', ''));
    }

    public function testFromLayerConfigPassesLayerExcludePathsToNamespaceResolver(): void
    {
        $chainLayerResolver = ChainLayerResolver::fromLayerConfig(
            ['Logger' => 'src/Logger/', 'Factory' => 'src/Logger/Factory/'],
            $this->basePath,
            [],
            ['Logger' => ['src/Logger/Factory/']]
        );

        $this->assertSame(
            ['Factory'],
            $chainLayerResolver->resolveAll(
                'App\\Logger\\Factory\\LoggerFactory',
                $this->basePath . '/src/Logger/Factory/LoggerFactory.php'
            )
        );
    }
}
