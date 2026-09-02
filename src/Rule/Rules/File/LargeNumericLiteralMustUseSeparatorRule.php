<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Analyser\AnalysisNodeExtractor;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FileAnalysisRuleInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Scalar\AddNumericLiteralSeparatorsVisitor;
use Boundwize\StructArmed\Rule\RuleViolation;
use InvalidArgumentException;
use PhpParser\NodeVisitor;

use function abs;
use function implode;
use function is_float;
use function is_int;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_split;
use function strrev;

/**
 * Requires large plain decimal numeric literals to group the digits before
 * their optional decimal point with PHP separators. Other numeric syntaxes
 * are deliberately ignored.
 */
final readonly class LargeNumericLiteralMustUseSeparatorRule extends AbstractPhpParserFixableRule implements
    FileAnalysisRuleInterface
{
    private PhpFileFinder $phpFileFinder;

    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private int $minimum = 10_000,
        ?array $sourcePaths = null,
        ?PhpFileFinder $phpFileFinder = null,
    ) {
        if ($this->minimum < 1) {
            throw new InvalidArgumentException('The minimum must be a positive integer.');
        }

        $this->phpFileFinder = $phpFileFinder ?? new PhpFileFinder($sourcePaths);
    }

    public function evaluateProject(string $basePath, Architecture $architecture, array $skipPaths = []): ?RuleViolation
    {
        return $this->evaluateProjectAll($basePath, $architecture, $skipPaths)[0] ?? null;
    }

    /**
     * @param list<string> $skipPaths
     * @return list<RuleViolation>
     */
    public function evaluateProjectAll(string $basePath, Architecture $architecture, array $skipPaths = []): array
    {
        $files            = $this->phpFileFinder->files($basePath, $skipPaths);
        $extractionResult = (new AnalysisNodeExtractor())->extract($files);

        return $this->evaluateFiles($files, new FileAnalysisProvider($extractionResult->fileAnalyses));
    }

    /**
     * @param list<string> $skipPaths
     * @return list<RuleViolation>
     */
    public function evaluateProjectAllWithProvider(
        string $basePath,
        Architecture $architecture,
        FileAnalysisProvider $fileAnalysisProvider,
        array $skipPaths = [],
    ): array {
        return $this->evaluateFiles(
            $this->phpFileFinder->filesFromScope(
                $basePath,
                $fileAnalysisProvider->scopeFiles(),
                $skipPaths,
            ),
            $fileAnalysisProvider,
        );
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): NodeVisitor
    {
        $literal = $ruleViolation->numericLiteral;

        return new AddNumericLiteralSeparatorsVisitor(
            $ruleViolation->line,
            $literal,
            $literal !== null ? $this->formatDecimalLiteral($literal) : null,
        );
    }

    /**
     * @param list<string> $files
     * @return list<RuleViolation>
     */
    private function evaluateFiles(array $files, FileAnalysisProvider $fileAnalysisProvider): array
    {
        $violations = [];

        foreach ($files as $file) {
            $fileAnalysis = $fileAnalysisProvider->analyse($file);
            $fileAnalysisProvider->releaseAst($file);

            foreach ($fileAnalysis->numericLiterals as [$line, $literal, $value]) {
                if (
                    (! is_int($value) && ! is_float($value))
                    || abs($value) < $this->minimum
                    || str_contains($literal, '_')
                ) {
                    continue;
                }

                $replacement = $this->formatDecimalLiteral($literal);

                if ($replacement === null || $replacement === $literal) {
                    continue;
                }

                $violations[] = new RuleViolation(
                    message: sprintf(
                        'Numeric literal [%s] must use separator formatting [%s]',
                        $literal,
                        $replacement,
                    ),
                    file: $file,
                    line: $line,
                    className: '',
                    numericLiteral: $literal,
                );
            }
        }

        return $violations;
    }

    private function formatDecimalLiteral(string $literal): ?string
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $literal) === 1) {
            return $this->groupDigits($literal);
        }

        if (preg_match('/^([0-9]+)\.([0-9]*)$/D', $literal, $matches) !== 1) {
            return null;
        }

        return $this->groupDigits($matches[1]) . '.' . $matches[2];
    }

    private function groupDigits(string $digits): string
    {
        return strrev(implode('_', str_split(strrev($digits), 3)));
    }
}
