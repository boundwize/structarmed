<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util\PhpParser;

use Boundwize\StructArmed\Util\PhpParser\ProtectedToPrivateFlags;
use Iterator;
use PhpParser\Modifiers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtectedToPrivateFlags::class)]
final class ProtectedToPrivateFlagsTest extends TestCase
{
    /**
     * @return Iterator<string, array{int, int|null}>
     */
    public static function provideFlags(): Iterator
    {
        yield 'no flags' => [0, null];
        yield 'public' => [Modifiers::PUBLIC, null];
        yield 'private' => [Modifiers::PRIVATE, null];
        yield 'final private' => [Modifiers::PRIVATE | Modifiers::FINAL, null];
        yield 'protected' => [Modifiers::PROTECTED, Modifiers::PRIVATE];
        yield 'protected static' => [Modifiers::PROTECTED | Modifiers::STATIC, Modifiers::PRIVATE | Modifiers::STATIC];
        yield 'final protected' => [Modifiers::PROTECTED | Modifiers::FINAL, Modifiers::PRIVATE];
        yield 'final protected static' => [
            Modifiers::PROTECTED | Modifiers::FINAL | Modifiers::STATIC,
            Modifiers::PRIVATE | Modifiers::STATIC,
        ];
    }

    #[DataProvider('provideFlags')]
    public function testTryFrom(int $flags, ?int $expected): void
    {
        $this->assertSame($expected, ProtectedToPrivateFlags::tryFrom($flags));
    }
}
