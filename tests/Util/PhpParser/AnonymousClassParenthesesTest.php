<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util\PhpParser;

use Boundwize\StructArmed\Util\PhpParser\AnonymousClassParentheses;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnonymousClassParentheses::class)]
final class AnonymousClassParenthesesTest extends TestCase
{
    #[DataProvider('anonymousClassProvider')]
    public function testLocatesEmptyParenthesesAfterClassKeyword(string $code, ?string $expectedRangeText): void
    {
        $parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $statements = $parser->parse($code);
        $tokens     = $parser->getTokens();

        $class = (new NodeFinder())->findFirstInstanceOf($statements ?? [], Class_::class);
        $this->assertInstanceOf(Class_::class, $class);

        $range = AnonymousClassParentheses::emptyTokenRange($tokens, $class);

        if ($expectedRangeText === null) {
            $this->assertNull($range);

            return;
        }

        $this->assertIsArray($range);
        [$first, $last] = $range;

        $rangeText = '';
        for ($index = $first; $index <= $last; $index++) {
            $rangeText .= $tokens[$index]->text;
        }

        $this->assertSame($expectedRangeText, $rangeText);
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function anonymousClassProvider(): iterable
    {
        yield 'no parentheses' => ['<?php new class {};', null];
        yield 'empty parentheses' => ['<?php new class() {};', '()'];
        yield 'spaced empty parentheses' => ['<?php new class ( ) {};', ' ( )'];
        yield 'newline inside parentheses' => ["<?php new class (\n) {};", " (\n)"];
        yield 'constructor argument' => ['<?php new class(1) {};', null];
        yield 'attribute and modifier before keyword' => ['<?php new #[Attr] readonly class () extends B {};', ' ()'];
        yield 'attribute argument with class constant' => ['<?php new #[Attr(Foo::class)] class () {};', ' ()'];
        yield 'attribute groups with class constants' => [
            '<?php new #[Attr(Foo::class), Other(Bar::class)] #[Third(Baz::class)] final class() {};',
            '()',
        ];
        yield 'comment inside parentheses' => ['<?php new class (/* none */) {};', null];
        yield 'comment before parentheses' => ['<?php new class /* c */ () {};', ' ()'];
        yield 'doc comment before parentheses' => ["<?php new class /** c */\n() {};", "\n()"];
        yield 'named class' => ['<?php class Foo {}', null];
    }

    public function testReturnsNullWithoutTokens(): void
    {
        $this->assertNull(AnonymousClassParentheses::emptyTokenRange([], new Class_(null)));
    }
}
