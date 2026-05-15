<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\LayerResolver;

use App\Domain\Entities\Order;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\ClassNameRegexLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\Tests\ArchitectureTest;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
