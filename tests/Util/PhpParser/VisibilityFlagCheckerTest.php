<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util\PhpParser;

use Boundwize\StructArmed\Util\PhpParser\VisibilityFlagChecker;
use Iterator;
use PhpParser\Modifiers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VisibilityFlagChecker::class)]
final class VisibilityFlagCheckerTest extends TestCase
{
    /**
     * @return Iterator<string, array{int, bool}>
     */
    public static function provideFlags(): Iterator
    {
        yield 'no flags' => [0, false];
        yield 'public' => [Modifiers::PUBLIC, true];
        yield 'protected' => [Modifiers::PROTECTED, true];
        yield 'private' => [Modifiers::PRIVATE, true];
        yield 'public readonly' => [Modifiers::PUBLIC | Modifiers::READONLY, true];
        yield 'readonly only' => [Modifiers::READONLY, false];
        yield 'static only' => [Modifiers::STATIC, false];
        yield 'final only' => [Modifiers::FINAL, false];
    }

    #[DataProvider('provideFlags')]
    public function testHasExplicitVisibilityFlag(int $flags, bool $expected): void
    {
        $this->assertSame($expected, VisibilityFlagChecker::hasExplicitVisibilityFlag($flags));
    }
}
