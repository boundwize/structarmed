<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util;

use Boundwize\StructArmed\Util\SourceLayerName;
use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceLayerName::class)]
final class SourceLayerNameTest extends TestCase
{
    /**
     * @return Iterator<string, array{string, bool}>
     */
    public static function provideMatches(): Iterator
    {
        yield 'bare' => ['Source', true];
        yield 'single path' => ['Source[src/]', true];
        yield 'multiple paths' => ['Source[lib/,src/]', true];
        yield 'empty brackets' => ['Source[]', true];
        yield 'ordinary layer' => ['Application', false];
        yield 'Source prefix only' => ['Sourcing', false];
        yield 'unclosed bracket' => ['Source[src/', false];
        yield 'different case' => ['source', false];
    }

    #[DataProvider('provideMatches')]
    public function testMatches(string $layerName, bool $expected): void
    {
        $this->assertSame($expected, SourceLayerName::matches($layerName));
    }
}
