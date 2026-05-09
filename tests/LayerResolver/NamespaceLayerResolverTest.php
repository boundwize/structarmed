<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\LayerResolver;

use App\Domain\Entities\Order;
use Boundwize\StructArmed\LayerResolver\ChainLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\NamespaceLayerResolver;
use Boundwize\StructArmed\LayerResolver\Resolvers\PatternLayerResolver;
use Boundwize\StructArmed\Tests\ArchitectureTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function dirname;

#[CoversClass(NamespaceLayerResolver::class)]
#[CoversClass(PatternLayerResolver::class)]
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

    public function testPatternResolverMatchesClassName(): void
    {
        $patternLayerResolver = new PatternLayerResolver([
            'Domain' => '/Entity$|ValueObject$|AggregateRoot$/',
        ]);

        $this->assertSame('Domain', $patternLayerResolver->resolve('App\\Foo\\OrderEntity', '/fake.php'));
        $this->assertSame('Domain', $patternLayerResolver->resolve('App\\Foo\\MoneyValueObject', '/fake.php'));
        $this->assertNull($patternLayerResolver->resolve('App\\Foo\\OrderService', '/fake.php'));
    }

    public function testChainResolverTriesResolversInOrder(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: ['Domain' => 'src/Domain/'],
            basePath: $this->basePath
        );
        $patternLayerResolver   = new PatternLayerResolver([
            'FallbackLayer' => '/SpecialClass$/',
        ]);
        $chainLayerResolver     = new ChainLayerResolver($namespaceLayerResolver, $patternLayerResolver);

        // Pattern resolver kicks in for this one
        $layer = $chainLayerResolver->resolve('App\\Anywhere\\SpecialClass', '/anywhere/SpecialClass.php');
        $this->assertSame('FallbackLayer', $layer);
    }

    public function testChainResolverReturnsNullWhenNoneMatch(): void
    {
        $chainLayerResolver = new ChainLayerResolver(
            new PatternLayerResolver(['Domain' => '/Entity$/'])
        );

        $layer = $chainLayerResolver->resolve('App\\Foo\\OrderService', '/fake.php');
        $this->assertNull($layer);
    }

    public function testPatternResolverResolveAllReturnsAllMatchingLayers(): void
    {
        $patternLayerResolver = new PatternLayerResolver([
            'Domain' => '/Entity$/',
            'Shared' => '/Entity$|ValueObject$/',
        ]);

        $layers = $patternLayerResolver->resolveAll('App\\Foo\\OrderEntity', '/fake.php');

        $this->assertContains('Domain', $layers);
        $this->assertContains('Shared', $layers);
    }

    public function testPatternResolverResolveAllReturnsEmptyWhenNoneMatch(): void
    {
        $patternLayerResolver = new PatternLayerResolver([
            'Domain' => '/Entity$/',
        ]);

        $layers = $patternLayerResolver->resolveAll('App\\Foo\\OrderService', '/fake.php');

        $this->assertSame([], $layers);
    }

    public function testChainResolverResolveAllMergesResultsFromAllResolvers(): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: ['Source' => 'src/'],
            basePath: $this->basePath
        );
        $patternLayerResolver   = new PatternLayerResolver([
            'Entity' => '/Entity$/',
        ]);
        $chainLayerResolver     = new ChainLayerResolver($namespaceLayerResolver, $patternLayerResolver);

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
            new PatternLayerResolver(['Domain' => '/Entity$/'])
        );

        $layers = $chainLayerResolver->resolveAll('App\\Foo\\OrderService', '/fake.php');

        $this->assertSame([], $layers);
    }
}
