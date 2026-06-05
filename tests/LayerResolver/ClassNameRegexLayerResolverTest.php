<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\LayerResolver;

use Boundwize\StructArmed\LayerResolver\Resolvers\ClassNameRegexLayerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassNameRegexLayerResolver::class)]
final class ClassNameRegexLayerResolverTest extends TestCase
{
    private function makeResolver(): ClassNameRegexLayerResolver
    {
        return new ClassNameRegexLayerResolver([
            'HTTP'   => ['pattern' => '/^App\\\\HTTP\\\\.*$/', 'excludePattern' => '/(Exception|URI)/'],
            'URI'    => ['pattern' => '/^App\\\\HTTP\\\\URI$/', 'excludePattern' => null],
            'Domain' => ['pattern' => '/^App\\\\Domain\\\\.*$/', 'excludePattern' => null],
        ]);
    }

    public function testResolvesLayerByFullyQualifiedClassName(): void
    {
        $classNameRegexLayerResolver = $this->makeResolver();

        $this->assertSame('Domain', $classNameRegexLayerResolver->resolve('App\\Domain\\Order', '/fake.php'));
    }

    public function testResolvesFirstMatchingLayer(): void
    {
        $classNameRegexLayerResolver = $this->makeResolver();

        $this->assertSame('HTTP', $classNameRegexLayerResolver->resolve('App\\HTTP\\Request', '/fake.php'));
    }

    public function testResolvesLayerWhenAnyConfiguredPatternMatches(): void
    {
        $classNameRegexLayerResolver = new ClassNameRegexLayerResolver([
            'Service' => [
                'pattern'        => [
                    '/^App\\\\Service\\\\.*$/',
                    '/^App\\\\Application\\\\.*Service$/',
                ],
                'excludePattern' => null,
            ],
        ]);

        $this->assertSame('Service', $classNameRegexLayerResolver->resolve(
            'App\\Application\\CreateOrderService',
            '/fake.php'
        ));
    }

    public function testExcludePatternPreventsMatch(): void
    {
        $classNameRegexLayerResolver = $this->makeResolver();

        // 'URI' is in the HTTP namespace but is excluded from the HTTP layer
        // by the excludePattern '/(Exception|URI)/'.
        // It should instead fall through to the 'URI' layer.
        $this->assertSame('URI', $classNameRegexLayerResolver->resolve('App\\HTTP\\URI', '/fake.php'));
    }

    public function testExcludePatternForExceptionClass(): void
    {
        $classNameRegexLayerResolver = $this->makeResolver();

        // Exception classes are excluded from the HTTP layer; since no other
        // pattern matches, the result is null.
        $this->assertNull($classNameRegexLayerResolver->resolve('App\\HTTP\\SomeException', '/fake.php'));
    }

    public function testExcludePatternsPreventMatchWhenAnyConfiguredPatternMatches(): void
    {
        $classNameRegexLayerResolver = new ClassNameRegexLayerResolver([
            'HTTP' => [
                'pattern'        => '/^App\\\\HTTP\\\\.*$/',
                'excludePattern' => [
                    '/Exception$/',
                    '/^App\\\\HTTP\\\\URI$/',
                ],
            ],
            'URI'  => ['pattern' => '/^App\\\\HTTP\\\\URI$/', 'excludePattern' => null],
        ]);

        $this->assertSame('URI', $classNameRegexLayerResolver->resolve('App\\HTTP\\URI', '/fake.php'));
        $this->assertNull($classNameRegexLayerResolver->resolve('App\\HTTP\\SomeException', '/fake.php'));
    }

    public function testExcludePatternArrayWithNullEntryDoesNotExcludeLayer(): void
    {
        $classNameRegexLayerResolver = new ClassNameRegexLayerResolver([
            'HTTP' => [
                'pattern'        => '/^App\\\\HTTP\\\\.*$/',
                'excludePattern' => [null],
            ],
        ]);

        $this->assertSame('HTTP', $classNameRegexLayerResolver->resolve('App\\HTTP\\Request', '/fake.php'));
        $this->assertSame(['HTTP'], $classNameRegexLayerResolver->resolveAll('App\\HTTP\\Request', '/fake.php'));
    }

    public function testReturnsNullForUnregisteredClass(): void
    {
        $classNameRegexLayerResolver = $this->makeResolver();

        $this->assertNull($classNameRegexLayerResolver->resolve('Vendor\\ThirdParty\\SomeClass', '/fake.php'));
    }

    public function testResolveAllReturnsAllMatchingLayers(): void
    {
        // Build a resolver where a class can match more than one layer
        $classNameRegexLayerResolver = new ClassNameRegexLayerResolver([
            'HTTP' => ['pattern' => '/^App\\\\HTTP\\\\.*$/', 'excludePattern' => null],
            'Core' => ['pattern' => '/^App\\\\.*$/', 'excludePattern' => null],
        ]);

        $layers = $classNameRegexLayerResolver->resolveAll('App\\HTTP\\Request', '/fake.php');

        $this->assertContains('HTTP', $layers);
        $this->assertContains('Core', $layers);
    }

    public function testResolveAllMatchesLayerWhenAnyConfiguredPatternMatches(): void
    {
        $classNameRegexLayerResolver = new ClassNameRegexLayerResolver([
            'Service' => [
                'pattern'        => [
                    '/^App\\\\Service\\\\.*$/',
                    '/^App\\\\Application\\\\.*Service$/',
                ],
                'excludePattern' => null,
            ],
            'Core'    => ['pattern' => '/^App\\\\.*$/', 'excludePattern' => null],
        ]);

        $layers = $classNameRegexLayerResolver->resolveAll('App\\Application\\CreateOrderService', '/fake.php');

        $this->assertContains('Service', $layers);
        $this->assertContains('Core', $layers);
    }

    public function testResolveAllReturnsEmptyArrayForUnregisteredClass(): void
    {
        $classNameRegexLayerResolver = $this->makeResolver();

        $this->assertSame([], $classNameRegexLayerResolver->resolveAll('Vendor\\ThirdParty\\SomeClass', '/fake.php'));
    }

    public function testResolveAllExcludesLayersWhereExcludePatternMatches(): void
    {
        $classNameRegexLayerResolver = $this->makeResolver();

        // 'App\HTTP\URI' matches the URI layer but is excluded from HTTP
        $layers = $classNameRegexLayerResolver->resolveAll('App\\HTTP\\URI', '/fake.php');

        $this->assertContains('URI', $layers);
        $this->assertNotContains('HTTP', $layers);
    }

    public function testResolveAllExcludesLayersWhereAnyExcludePatternMatches(): void
    {
        $classNameRegexLayerResolver = new ClassNameRegexLayerResolver([
            'HTTP' => [
                'pattern'        => '/^App\\\\HTTP\\\\.*$/',
                'excludePattern' => [
                    '/Exception$/',
                    '/^App\\\\HTTP\\\\URI$/',
                ],
            ],
            'URI'  => ['pattern' => '/^App\\\\HTTP\\\\URI$/', 'excludePattern' => null],
        ]);

        $layers = $classNameRegexLayerResolver->resolveAll('App\\HTTP\\URI', '/fake.php');

        $this->assertContains('URI', $layers);
        $this->assertNotContains('HTTP', $layers);
    }
}
