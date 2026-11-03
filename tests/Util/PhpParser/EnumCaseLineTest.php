<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Util\PhpParser;

use Boundwize\StructArmed\Util\PhpParser\EnumCaseLine;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(EnumCaseLine::class)]
final class EnumCaseLineTest extends TestCase
{
    /** @param list<bool> $expected One per case, in declaration order */
    #[DataProvider('enumProvider')]
    public function testDetectsCasesSharingALine(string $code, array $expected): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $cases  = (new NodeFinder())->findInstanceOf($parser->parse("<?php\n" . $code) ?? [], EnumCase::class);
        $tokens = $parser->getTokens();

        $this->assertSame(
            $expected,
            array_map(static fn (EnumCase $enumCase): bool => EnumCaseLine::sharesLine($tokens, $enumCase), $cases)
        );
    }

    /** @return iterable<string, array{string, list<bool>}> */
    public static function enumProvider(): iterable
    {
        yield 'pure enum on own lines' => ["enum S\n{\n    case A;\n    case B;\n}\n", [false, false]];
        yield 'backed enum on own lines' => ["enum S: int\n{\n    case A = 1;\n    case B = 2;\n}\n", [false, false]];
        yield 'two cases on one line' => ["enum S\n{\n    case A; case B;\n    case C;\n}\n", [true, true, false]];
        yield 'opening brace' => ["enum S { case A;\n}\n", [true]];
        yield 'closing brace' => ["enum S {\n    case A; }\n", [true]];
        yield 'constant' => ["enum S {\n    const X = 1; case A;\n}\n", [true]];
        yield 'method' => ["enum S {\n    case A; public function f() {}\n}\n", [true]];
        yield 'comment between cases' => ["enum S {\n    case A; /* b */ case B;\n}\n", [true, true]];
        yield 'trailing line comment' => ["enum S {\n    case A; // a\n    case B;\n}\n", [false, false]];
        yield 'multiline comment between cases' => ["enum S {\n    case A; /*\n */ case B;\n}\n", [false, false]];
        yield 'multiline backing expression' => [
            "enum S: string {\n    case A = 'a'\n        . 'b'; case B = 'c';\n    case C = 'd'\n        . 'e';\n}\n",
            [true, true, false],
        ];
    }

    public function testReportsNoGapForNodeWithoutTokenPositions(): void
    {
        $enumCase = new EnumCase('A');

        $this->assertFalse(EnumCaseLine::sharesLine([], $enumCase));
        $this->assertNull(EnumCaseLine::precedingGap([], $enumCase));
        $this->assertNull(EnumCaseLine::followingGap([], $enumCase));
    }
}
