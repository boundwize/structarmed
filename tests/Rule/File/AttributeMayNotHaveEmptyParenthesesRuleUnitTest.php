<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Attribute\RemoveAttributeParenthesesVisitor;
use Boundwize\StructArmed\Rule\Rules\File\AttributeMayNotHaveEmptyParenthesesRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(AttributeMayNotHaveEmptyParenthesesRule::class)]
#[CoversClass(RemoveAttributeParenthesesVisitor::class)]
final class AttributeMayNotHaveEmptyParenthesesRuleUnitTest extends TestCase
{
    private const BASE_PATH = '/project';

    private const SOURCE_FILE = self::BASE_PATH . '/src/Foo.php';

    private const TEST_FILE = self::BASE_PATH . '/tests/FooTest.php';

    public function testIsFixable(): void
    {
        $this->assertInstanceOf(FixableInterface::class, new AttributeMayNotHaveEmptyParenthesesRule(['src/']));
    }

    public function testReportsCollectedAttributesWithinSourcePaths(): void
    {
        $fileAnalysisProvider = FileAnalysisProvider::forScope(
            [
                self::SOURCE_FILE => $this->makeFileAnalysis(self::SOURCE_FILE, [[3, 'Acme\Marker'], [9, 'Override']]),
                self::TEST_FILE   => $this->makeFileAnalysis(self::TEST_FILE, [[5, 'Acme\Other']]),
            ],
            [self::SOURCE_FILE, self::TEST_FILE],
        );

        $violations = (new AttributeMayNotHaveEmptyParenthesesRule(['src/']))->evaluateProjectAllWithProvider(
            self::BASE_PATH,
            Architecture::define(),
            $fileAnalysisProvider,
        );

        $this->assertSame(
            [
                'Attribute [Acme\Marker] may not have empty parentheses',
                'Attribute [Override] may not have empty parentheses',
            ],
            array_map(static fn (RuleViolation $ruleViolation): string => $ruleViolation->message, $violations),
        );
        $this->assertSame(
            [3, 9],
            array_map(static fn (RuleViolation $ruleViolation): int => $ruleViolation->line, $violations),
        );
        $this->assertSame(self::SOURCE_FILE, $violations[0]->file);
    }

    public function testPassesFileWithoutCollectedAttributes(): void
    {
        $fileAnalysisProvider = FileAnalysisProvider::forScope(
            [self::SOURCE_FILE => $this->makeFileAnalysis(self::SOURCE_FILE, [])],
            [self::SOURCE_FILE],
        );

        $this->assertSame(
            [],
            (new AttributeMayNotHaveEmptyParenthesesRule(['src/']))->evaluateProjectAllWithProvider(
                self::BASE_PATH,
                Architecture::define(),
                $fileAnalysisProvider,
            ),
        );
    }

    public function testVisitorBlanksTheEmptyParenthesesTokensOfAttributesOnItsLine(): void
    {
        $parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $statements = $parser->parse(<<<'PHP'
            <?php

            #[First ()]
            #[Second()]
            function run(): void
            {
            }

            PHP);
        $tokens     = $parser->getTokens();

        [$first, $second] = (new NodeFinder())->findInstanceOf($statements ?? [], Attribute::class);

        $removeAttributeParenthesesVisitor = new RemoveAttributeParenthesesVisitor(3);
        $removeAttributeParenthesesVisitor->setTokens($tokens);

        $this->assertSame($first, $removeAttributeParenthesesVisitor->enterNode($first));
        $this->assertNotInstanceOf(Node::class, $removeAttributeParenthesesVisitor->enterNode($second));
        $this->assertNotInstanceOf(Node::class, $removeAttributeParenthesesVisitor->enterNode($first->name));

        $code = '';

        foreach ($tokens as $token) {
            $code .= $token->text;
        }

        $this->assertStringContainsString("#[First]\n#[Second()]\nfunction run(): void", $code);

        // The parentheses are gone, so the same attribute yields no range again.
        $this->assertNotInstanceOf(Node::class, $removeAttributeParenthesesVisitor->enterNode($first));
    }

    public function testVisitorWithoutTokensDoesNothing(): void
    {
        $removeAttributeParenthesesVisitor = new RemoveAttributeParenthesesVisitor(3);

        $this->assertNotInstanceOf(
            Node::class,
            $removeAttributeParenthesesVisitor->enterNode(new Attribute(new Name('Foo'), [], ['startLine' => 3])),
        );
    }

    /** @param list<array{int, string}> $attributesWithEmptyParentheses */
    private function makeFileAnalysis(string $file, array $attributesWithEmptyParentheses): FileAnalysis
    {
        return new FileAnalysis(
            file: $file,
            hasUtf8Bom: false,
            hasValidUtf8: true,
            invalidPhpTagLine: null,
            hasValidAst: true,
            declaresSymbols: true,
            hasSideEffects: false,
            sideEffectLine: 1,
            attributesWithEmptyParentheses: $attributesWithEmptyParentheses,
        );
    }
}
