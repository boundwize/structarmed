<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Tests\Rule\File;

use Boundwize\StructArmed\Analyser\FileAnalysis;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Scalar\AddNumericLiteralSeparatorsVisitor;
use Boundwize\StructArmed\Rule\Rules\File\LargeNumericLiteralMustUseSeparatorRule;
use Boundwize\StructArmed\Rule\RuleViolation;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Scalar\Int_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(LargeNumericLiteralMustUseSeparatorRule::class)]
#[CoversClass(AddNumericLiteralSeparatorsVisitor::class)]
final class LargeNumericLiteralMustUseSeparatorRuleUnitTest extends TestCase
{
    private const BASE_PATH = '/project';

    private const FILE = self::BASE_PATH . '/src/Foo.php';

    public function testReportsLargeUnformattedDecimalIntegers(): void
    {
        $largeNumericLiteralMustUseSeparatorRule = new LargeNumericLiteralMustUseSeparatorRule(
            sourcePaths: ['src/'],
        );
        $violations                              = $this->evaluate(
            [[3, '10000', 10000], [4, '100000', 100000], [5, '1000000', 1000000]],
            largeNumericLiteralMustUseSeparatorRule: $largeNumericLiteralMustUseSeparatorRule,
        );

        $this->assertInstanceOf(FixableInterface::class, $largeNumericLiteralMustUseSeparatorRule);
        $this->assertSame(
            [
                'Numeric literal [10000] must use separator formatting [10_000]',
                'Numeric literal [100000] must use separator formatting [100_000]',
                'Numeric literal [1000000] must use separator formatting [1_000_000]',
            ],
            array_map(static fn (RuleViolation $ruleViolation): string => $ruleViolation->message, $violations),
        );
        $this->assertSame(
            [3, 4, 5],
            array_map(static fn (RuleViolation $ruleViolation): int => $ruleViolation->line, $violations),
        );
        $this->assertSame(
            ['10000', '100000', '1000000'],
            array_map(
                static fn (RuleViolation $ruleViolation): ?string => $ruleViolation->numericLiteral,
                $violations,
            ),
        );
    }

    public function testIgnoresBelowThresholdAndAlreadySeparatedIntegers(): void
    {
        $this->assertSame([], $this->evaluate([
            [3, '9999', 9999],
            [4, '10_000', 10000],
            [5, '1_000_000', 1000000],
            [6, '9999.99', 9999.99],
            [7, '10_000.0', 10000.0],
        ]));
    }

    public function testReportsPlainDecimalFloatsAndPreservesTheirFractionalParts(): void
    {
        $violations = $this->evaluate([
            [3, '10000.0', 10000.0],
            [4, '1000000.0', 1000000.0],
            [5, '1000500.001', 1000500.001],
        ]);

        $this->assertSame(
            [
                'Numeric literal [10000.0] must use separator formatting [10_000.0]',
                'Numeric literal [1000000.0] must use separator formatting [1_000_000.0]',
                'Numeric literal [1000500.001] must use separator formatting [1_000_500.001]',
            ],
            array_map(static fn (RuleViolation $ruleViolation): string => $ruleViolation->message, $violations),
        );
    }

    public function testSupportsCustomMinimum(): void
    {
        $violations = $this->evaluate(
            [[3, '1000', 1000]],
            new LargeNumericLiteralMustUseSeparatorRule(minimum: 1_000, sourcePaths: ['src/']),
        );

        $this->assertCount(1, $violations);
        $this->assertSame(
            'Numeric literal [1000] must use separator formatting [1_000]',
            $violations[0]->message,
        );
    }

    public function testRejectsNonPositiveMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The minimum must be a positive integer.');

        new LargeNumericLiteralMustUseSeparatorRule(minimum: 0);
    }

    public function testIgnoresUnsupportedNumericSyntaxesAndFloats(): void
    {
        $this->assertSame([], $this->evaluate([
            [3, '0xFFFFFF', 16777215],
            [4, '0b11111111', 255],
            [5, '0o755', 493],
            [6, '077777', 32767],
            [7, '1e10', 10000000000.0],
            [8, '1.2e6', 1200000.0],
        ]));
    }

    public function testUsesTheLiteralMagnitudeCollectedInsideAUnaryMinus(): void
    {
        $violations = $this->evaluate([[3, '100000', 100000]]);

        $this->assertCount(1, $violations);
        $this->assertSame('100000', $violations[0]->numericLiteral);
    }

    public function testVisitorWithoutLiteralPayloadDoesNothing(): void
    {
        $int                                = Int_::fromString('10000', ['startLine' => 3]);
        $addNumericLiteralSeparatorsVisitor = new AddNumericLiteralSeparatorsVisitor(3, null, null);

        $this->assertNotInstanceOf(Node::class, $addNumericLiteralSeparatorsVisitor->enterNode($int));
        $this->assertSame('10000', $int->getAttribute('rawValue'));
    }

    public function testDoesNotReportAValueTooShortToContainASeparator(): void
    {
        $this->assertSame(
            [],
            $this->evaluate(
                [[3, '1', 1]],
                new LargeNumericLiteralMustUseSeparatorRule(minimum: 1, sourcePaths: ['src/']),
            ),
        );
    }

    /**
     * @param list<array{int, string, int|float}> $numericLiterals
     * @return list<RuleViolation>
     */
    private function evaluate(
        array $numericLiterals,
        ?LargeNumericLiteralMustUseSeparatorRule $largeNumericLiteralMustUseSeparatorRule = null,
    ): array {
        $fileAnalysis         = new FileAnalysis(
            file: self::FILE,
            hasUtf8Bom: false,
            hasValidUtf8: true,
            invalidPhpTagLine: null,
            hasValidAst: true,
            declaresSymbols: false,
            hasSideEffects: true,
            sideEffectLine: 3,
            numericLiterals: $numericLiterals,
        );
        $fileAnalysisProvider = FileAnalysisProvider::forScope(
            [self::FILE => $fileAnalysis],
            [self::FILE],
        );

        return ($largeNumericLiteralMustUseSeparatorRule
            ?? new LargeNumericLiteralMustUseSeparatorRule(sourcePaths: ['src/']))
            ->evaluateProjectAllWithProvider(self::BASE_PATH, Architecture::define(), $fileAnalysisProvider);
    }
}
