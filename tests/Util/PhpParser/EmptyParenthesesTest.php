<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util\PhpParser;

use Boundwize\StructArmed\Util\PhpParser\EmptyParentheses;
use PhpParser\Node\Attribute;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmptyParentheses::class)]
final class EmptyParenthesesTest extends TestCase
{
    #[DataProvider('attributeProvider')]
    public function testLocatesEmptyParenthesesAfterAttributeName(string $code, ?string $expectedRangeText): void
    {
        $parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $statements = $parser->parse($code);
        $tokens     = $parser->getTokens();

        $attribute = (new NodeFinder())->findFirstInstanceOf($statements ?? [], Attribute::class);
        $this->assertInstanceOf(Attribute::class, $attribute);

        $range = EmptyParentheses::tokenRangeAfter($tokens, $attribute->name->getEndTokenPos());

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
    public static function attributeProvider(): iterable
    {
        yield 'no parentheses' => ['<?php #[Attr] class Foo {}', null];
        yield 'empty parentheses' => ['<?php #[Attr()] class Foo {}', '()'];
        yield 'spaced empty parentheses' => ['<?php #[Attr ( )] class Foo {}', ' ( )'];
        yield 'newline inside parentheses' => ["<?php #[Attr (\n)] class Foo {}", " (\n)"];
        yield 'argument' => ['<?php #[Attr(1)] class Foo {}', null];
        yield 'qualified name' => ['<?php #[\Foo\Attr()] class Foo {}', '()'];
        yield 'followed by another attribute' => ['<?php #[Attr(), Other(1)] class Foo {}', '()'];
        yield 'preceded by another attribute' => ['<?php #[Attr, Other()] class Foo {}', null];
        yield 'comment inside parentheses' => ['<?php #[Attr(/* none */)] class Foo {}', null];
        yield 'comment before parentheses' => ['<?php #[Attr /* c */ ()] class Foo {}', ' ()'];
        yield 'doc comment before parentheses' => ["<?php #[Attr /** c */\n()] class Foo {}", "\n()"];
        yield 'hash comment before parentheses' => ["<?php #[Attr # c\n ()] class Foo {}", '()'];
        yield 'slash comment before parentheses' => ["<?php #[Attr // c\n\t()] class Foo {}", '()'];
        yield 'line comment then block comment before parentheses' => [
            "<?php #[Attr # c\n/* d */ ()] class Foo {}",
            ' ()',
        ];
    }

    public function testReturnsNullWithoutTokens(): void
    {
        $this->assertNull(EmptyParentheses::tokenRangeAfter([], 0));
    }
}
