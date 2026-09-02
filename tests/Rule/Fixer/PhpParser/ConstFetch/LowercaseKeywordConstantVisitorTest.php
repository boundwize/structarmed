<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\Fixer\PhpParser\ConstFetch;

use Boundwize\StructArmed\Rule\Fixer\PhpParser\ConstFetch\LowercaseKeywordConstantVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LowercaseKeywordConstantVisitor::class)]
final class LowercaseKeywordConstantVisitorTest extends TestCase
{
    public function testLowercasesUnqualifiedKeywordConstantOnLine(): void
    {
        $this->assertSame(
            "\$a = FOO;\n\$b = true;",
            $this->apply("<?php\n\$a = FOO;\n\$b = tRuE;", new LowercaseKeywordConstantVisitor(3))
        );
    }

    public function testKeepsLeadingBackslashOfFullyQualifiedKeywordConstant(): void
    {
        $this->assertSame(
            'return \null;',
            $this->apply('<?php return \NULL;', new LowercaseKeywordConstantVisitor(1, 'NULL'))
        );
    }

    public function testKeepsRelativeSpelling(): void
    {
        $this->assertSame(
            'return namespace\false;',
            $this->apply('<?php return namespace\FALSE;', new LowercaseKeywordConstantVisitor(1))
        );
    }

    public function testIgnoresOtherLines(): void
    {
        $this->assertSame(
            "\$a = TRUE;\n\$b = FALSE;",
            $this->apply("<?php\n\$a = TRUE;\n\$b = FALSE;", new LowercaseKeywordConstantVisitor(4))
        );
    }

    public function testIgnoresUnrelatedAndCanonicalConstants(): void
    {
        $code = '<?php $a = FOO ?? \BAR ?? Foo\BAZ ?? Some::TRUE ?? true ?? \null;';

        $this->assertSame(
            '$a = FOO ?? \BAR ?? Foo\BAZ ?? Some::TRUE ?? true ?? \null;',
            $this->apply($code, new LowercaseKeywordConstantVisitor(1))
        );
    }

    public function testMatchesOnlyTheGivenSpelling(): void
    {
        $this->assertSame(
            '$a = True && true;',
            $this->apply('<?php $a = True && TRUE;', new LowercaseKeywordConstantVisitor(1, 'TRUE'))
        );
    }

    public function testFixesEveryMatchingSpellingOnTheLine(): void
    {
        $this->assertSame(
            '$a = true && true;',
            $this->apply('<?php $a = TRUE && TRUE;', new LowercaseKeywordConstantVisitor(1, 'TRUE'))
        );
    }

    private function apply(string $code, LowercaseKeywordConstantVisitor $lowercaseKeywordConstantVisitor): string
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        $this->assertNotNull($statements);

        $statements = (new NodeTraverser($lowercaseKeywordConstantVisitor))->traverse($statements);

        return (new Standard())->prettyPrint($statements);
    }
}
