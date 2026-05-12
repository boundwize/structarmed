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
            'HTTP'       => ['pattern' => '/^App\\\\HTTP\\\\.*$/', 'excludePattern' => '/(Exception|URI)/'],
            'URI'        => ['pattern' => '/^App\\\\HTTP\\\\URI$/', 'excludePattern' => null],
            'Domain'     => ['pattern' => '/^App\\\\Domain\\\\.*$/', 'excludePattern' => null],
        ]);
    }

    public function testResolvesLayerByFullyQualifiedClassName(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('Domain', $resolver->resolve('App\\Domain\\Order', '/fake.php'));
    }

    public function testResolvesFirstMatchingLayer(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('HTTP', $resolver->resolve('App\\HTTP\\Request', '/fake.php'));
    }

    public function testExcludePatternPreventsMatch(): void
    {
        $resolver = $this->makeResolver();

        // 'URI' is in the HTTP namespace but is excluded from the HTTP layer
        // by the excludePattern '/(Exception|URI)/'.
        // It should instead fall through to the 'URI' layer.
        $this->assertSame('URI', $resolver->resolve('App\\HTTP\\URI', '/fake.php'));
    }

    public function testExcludePatternForExceptionClass(): void
    {
        $resolver = $this->makeResolver();

        // Exception classes are excluded from the HTTP layer; since no other
        // pattern matches, the result is null.
        $this->assertNull($resolver->resolve('App\\HTTP\\SomeException', '/fake.php'));
    }

    public function testReturnsNullForUnregisteredClass(): void
    {
        $resolver = $this->makeResolver();

        $this->assertNull($resolver->resolve('Vendor\\ThirdParty\\SomeClass', '/fake.php'));
    }

    public function testResolveAllReturnsAllMatchingLayers(): void
    {
        // Build a resolver where a class can match more than one layer
        $resolver = new ClassNameRegexLayerResolver([
            'HTTP' => ['pattern' => '/^App\\\\HTTP\\\\.*$/', 'excludePattern' => null],
            'Core' => ['pattern' => '/^App\\\\.*$/', 'excludePattern' => null],
        ]);

        $layers = $resolver->resolveAll('App\\HTTP\\Request', '/fake.php');

        $this->assertContains('HTTP', $layers);
        $this->assertContains('Core', $layers);
    }

    public function testResolveAllReturnsEmptyArrayForUnregisteredClass(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame([], $resolver->resolveAll('Vendor\\ThirdParty\\SomeClass', '/fake.php'));
    }

    public function testResolveAllExcludesLayersWhereExcludePatternMatches(): void
    {
        $resolver = $this->makeResolver();

        // 'App\HTTP\URI' matches the URI layer but is excluded from HTTP
        $layers = $resolver->resolveAll('App\\HTTP\\URI', '/fake.php');

        $this->assertContains('URI', $layers);
        $this->assertNotContains('HTTP', $layers);
    }
}
