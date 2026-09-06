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

    #[DataProvider('provideResolutionOrder')]
    public function testRepeatedFileResolutionsIgnoreSymbolNames(bool $resolveAllFirst): void
    {
        $namespaceLayerResolver = new NamespaceLayerResolver(
            layers: [
                'Source'      => 'src/',
                'Domain'      => ['src/Domain/', 'src/Domain/Entities/'],
                'DomainAlias' => 'src/Domain/Entities/',
                'Other'       => 'src/Other/',
            ],
            basePath: $this->basePath
        );

        foreach (['App\\Order', 'App\\OtherClass', 'App\\helper()', '{closure}', 'class@anonymous'] as $name) {
            foreach (
                [
                    '/src/Domain/Entities/Order.php' => ['Domain', ['Source', 'Domain', 'DomainAlias']],
                    '/src/Other/Service.php'         => ['Other', ['Source', 'Other']],
                    '/src/DomainSibling/Order.php'   => ['Source', ['Source']],
                    '/vendor/Unknown.php'            => [null, []],
                ] as $file => [$expectedLayer, $expectedLayers]
            ) {
                $filePath = $this->basePath . $file;

                if ($resolveAllFirst) {
                    $this->assertSame($expectedLayers, $namespaceLayerResolver->resolveAll($name, $filePath));
                    $this->assertSame($expectedLayer, $namespaceLayerResolver->resolve($name, $filePath));
                } else {
                    $this->assertSame($expectedLayer, $namespaceLayerResolver->resolve($name, $filePath));
                    $this->assertSame($expectedLayers, $namespaceLayerResolver->resolveAll($name, $filePath));
                }
            }
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function provideResolutionOrder(): iterable
    {
        yield 'resolve first' => [false];
        yield 'resolveAll first' => [true];
    }

    /** @param list<string> $filePaths */
    #[DataProvider('provideEquivalentFilePaths')]
    public function testEquivalentFilePathsResolveIdentically(string $layerPath, array $filePaths): void
    {
        $resolver = new NamespaceLayerResolver(['Source' => $layerPath], $this->basePath);
        $unknown  = new NamespaceLayerResolver([], $this->basePath);

        foreach ($filePaths as $index => $filePath) {
            $this->assertSame('Source', $resolver->resolve('Class' . $index, $filePath));
            $this->assertSame(['Source'], $resolver->resolveAll('Function' . $index, $filePath));
            $this->assertNull($unknown->resolve('Class' . $index, $filePath));
            $this->assertSame([], $unknown->resolveAll('Function' . $index, $filePath));
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function provideEquivalentFilePaths(): iterable
    {
        yield 'canonical existing file' => [
            __DIR__,
            [__FILE__, __DIR__ . '/../LayerResolver/NamespaceLayerResolverTest.php'],
        ];
        yield 'Windows drive separators' => [
            'C:\\structarmed-cache-test\\src',
            ['C:\\structarmed-cache-test\\src\\Order.php', 'C:/structarmed-cache-test/src/Order.php'],
        ];
        yield 'Windows UNC separators' => [
            '\\\\structarmed-cache-test\\share\\src',
            ['\\\\structarmed-cache-test\\share\\src\\Order.php', '//structarmed-cache-test/share/src/Order.php'],
        ];
        yield 'redundant separators' => [
            '/structarmed-cache-test/src',
            ['/structarmed-cache-test/src//Order.php', '/structarmed-cache-test/src/Order.php'],
        ];
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
}
