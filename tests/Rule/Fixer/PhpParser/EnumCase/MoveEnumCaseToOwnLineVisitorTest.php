<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\EnumCase;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\EnumCase\MoveEnumCaseToOwnLineVisitor;
use Boundwize\StructArmed\Util\PhpParser\EnumCaseLine;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_reverse;
use function str_replace;

#[CoversClass(MoveEnumCaseToOwnLineVisitor::class)]
#[CoversClass(EnumCaseLine::class)]
final class MoveEnumCaseToOwnLineVisitorTest extends TestCase
{
    /**
     * @param list<string> $caseNames
     */
    #[DataProvider('enumProvider')]
    public function testMovesEnumCasesToOwnLine(string $code, array $caseNames, string $expected): void
    {
        $this->assertSame($expected, $this->fix($code, $caseNames));

        // The breaks one fix inserts are the breaks another case on the line
        // needs, so the order the violations arrive in does not matter.
        $this->assertSame($expected, $this->fix($code, array_reverse($caseNames)));

        // A fixed enum is left alone.
        $this->assertSame($expected, $this->fix($expected, $caseNames));
    }

    /** @return iterable<string, array{string, list<string>, string}> */
    public static function enumProvider(): iterable
    {
        yield 'two cases on one line' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    case Active; case Inactive;
                }
                PHP,
            ['Active', 'Inactive'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    case Active;
                    case Inactive;
                }
                PHP,
        ];

        yield 'line comment above the group is mirrored' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    // Available status values
                    case Active; case Inactive;
                }
                PHP,
            ['Active', 'Inactive'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    // Available status values
                    case Active;
                    // Available status values
                    case Inactive;
                }
                PHP,
        ];

        yield 'block comments above the group are mirrored, doc comment is not' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    /*
                     * Group
                     */
                    # hash
                    /** @deprecated */
                    case Active; case Inactive;case Pending;
                }
                PHP,
            ['Active', 'Inactive', 'Pending'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    /*
                     * Group
                     */
                    # hash
                    /** @deprecated */
                    case Active;
                    /*
                     * Group
                     */
                    # hash
                    case Inactive;
                    /*
                     * Group
                     */
                    # hash
                    case Pending;
                }
                PHP,
        ];

        yield 'only a doc comment above the group is not mirrored' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    /** @deprecated */
                    case Active; case Inactive;
                }
                PHP,
            ['Active', 'Inactive'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    /** @deprecated */
                    case Active;
                    case Inactive;
                }
                PHP,
        ];

        yield 'comment between cases stays with the following case' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    // group
                    case Active; /* Inactive status */ case Inactive; /** doc */ case Pending; case Done;
                }
                PHP,
            ['Active', 'Inactive', 'Pending', 'Done'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    // group
                    case Active;
                    /* Inactive status */
                    case Inactive;
                    /** doc */
                    case Pending;
                    case Done;
                }
                PHP,
        ];

        yield 'comment trailing the opening brace is not mirrored' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status { // trailing
                    case Active; case Inactive;
                }
                PHP,
            ['Active', 'Inactive'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status { // trailing
                    case Active;
                    case Inactive;
                }
                PHP,
        ];

        yield 'comment of a constant is not mirrored onto cases' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    // the constant
                    const Default = self::Active; case Active; case Inactive;
                }
                PHP,
            ['Active', 'Inactive'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    // the constant
                    const Default = self::Active;
                    case Active;
                    case Inactive;
                }
                PHP,
        ];

        yield 'inline enum' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status { case Active; case Inactive; }
                PHP,
            ['Active', 'Inactive'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status {
                    case Active;
                    case Inactive;
                }
                PHP,
        ];

        yield 'inline backed enum keeps backing expressions' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status: string { case Active = 'act' . 'ive'; case Inactive = self::PREFIX . 'inactive'; }
                PHP,
            ['Active', 'Inactive'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status: string {
                    case Active = 'act' . 'ive';
                    case Inactive = self::PREFIX . 'inactive';
                }
                PHP,
        ];

        yield 'case sharing lines with a method and a trailing comment before the brace' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    public function label(): string { return ''; } case Active; /* end */ }
                PHP,
            ['Active'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    public function label(): string { return ''; }
                    case Active;
                    /* end */
                }
                PHP,
        ];

        yield 'multiline backing expression' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status: string
                {
                    case Active = 'act'
                        . 'ive'; case Inactive = 'inactive';
                    case Pending = 'pending'; // note
                }
                PHP,
            ['Active', 'Inactive'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status: string
                {
                    case Active = 'act'
                        . 'ive';
                    case Inactive = 'inactive';
                    case Pending = 'pending'; // note
                }
                PHP,
        ];

        yield 'attributed case' => [
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    case Active; #[Deprecated] case Inactive;
                }
                PHP,
            ['Active', 'Inactive'],
            <<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    case Active;
                    #[Deprecated] case Inactive;
                }
                PHP,
        ];

        yield 'file without a newline' => [
            '<?php namespace App; enum Status { case Active; case Inactive; }',
            ['Active', 'Inactive'],
            "<?php namespace App; enum Status {\n    case Active;\n    case Inactive;\n}",
        ];

        yield 'tab indentation in a bracketed namespace' => [
            "<?php\nnamespace App {\n\tenum Status { case Active; case Inactive; }\n}\n",
            ['Active', 'Inactive'],
            "<?php\nnamespace App {\n\tenum Status {\n\t\tcase Active;\n\t\tcase Inactive;\n\t}\n}\n",
        ];

        $crlf = static fn (string $code): string => str_replace("\n", "\r\n", $code);

        yield 'CRLF line endings' => [
            $crlf(<<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    // group
                    case Active; case Inactive;
                }
                PHP),
            ['Active', 'Inactive'],
            $crlf(<<<'PHP'
                <?php
                namespace App;
                enum Status
                {
                    // group
                    case Active;
                    // group
                    case Inactive;
                }
                PHP),
        ];
    }

    public function testFixesOnlyTheNamedCaseOfTheNamedEnum(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            enum Status { case Active; case Inactive; case Pending; } enum Other { case Active; case Inactive; }
            PHP;

        $this->assertSame(<<<'PHP'
            <?php
            namespace App;
            enum Status { case Active; case Inactive;
                case Pending;
            } enum Other { case Active; case Inactive; }
            PHP, $this->fix($code, ['Pending']));
    }

    /**
     * The break between two cases is the fix of the case after it; the case
     * before it only breaks before a non-case member or the closing brace.
     */
    public function testBreaksOnlyBeforeOwnCaseWhenAnotherCaseFollows(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            enum Status
            {
                case Active; case Inactive; const Default = self::Active;
            }
            PHP;

        $this->assertSame($code, $this->fix($code, ['Active']));
        $this->assertSame(<<<'PHP'
            <?php
            namespace App;
            enum Status
            {
                case Active;
                case Inactive;
                const Default = self::Active;
            }
            PHP, $this->fix($code, ['Inactive']));
    }

    public function testReturnsNodeOnlyWhenChanged(): void
    {
        $parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $statements = (new NodeTraverser(new NameResolver()))->traverse($parser->parse(<<<'PHP'
            <?php
            enum Status { case Active; case Inactive; }
            enum Other
            {
                case Active;
            }
            PHP) ?? []);

        $enums = (new NodeFinder())->findInstanceOf($statements, Enum_::class);

        $moveEnumCaseToOwnLineVisitor = new MoveEnumCaseToOwnLineVisitor('Status', 'Active');
        $moveEnumCaseToOwnLineVisitor->setTokens($parser->getTokens());

        $this->assertSame($enums[0], $moveEnumCaseToOwnLineVisitor->enterNode($enums[0]));
        $this->assertNull($moveEnumCaseToOwnLineVisitor->enterNode($enums[0]));
        $this->assertNull($moveEnumCaseToOwnLineVisitor->enterNode($enums[1]));
        $this->assertNull((new MoveEnumCaseToOwnLineVisitor('Status', 'Missing'))->enterNode($enums[0]));
    }

    /** @param list<string> $caseNames */
    private function fix(string $code, array $caseNames): string
    {
        $parser             = (new ParserFactory())->createForNewestSupportedVersion();
        $originalStatements = $parser->parse($code) ?? [];
        $tokens             = $parser->getTokens();
        $statements         = (new NodeTraverser(new CloningVisitor()))->traverse($originalStatements);
        $statements         = (new NodeTraverser(new NameResolver(options: ['replaceNodes' => false])))
            ->traverse($statements);

        foreach ($caseNames as $caseName) {
            $moveEnumCaseToOwnLineVisitor = new MoveEnumCaseToOwnLineVisitor('App\\Status', $caseName);
            $moveEnumCaseToOwnLineVisitor->setTokens($tokens);

            $statements = (new NodeTraverser($moveEnumCaseToOwnLineVisitor))->traverse($statements);
        }

        return (new Standard())->printFormatPreserving($statements, $originalStatements, $tokens);
    }
}
