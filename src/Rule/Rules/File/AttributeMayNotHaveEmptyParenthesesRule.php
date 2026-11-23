<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Analyser\Capture\AnalysisNodeExtractor;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FileAnalysisRuleInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\Attribute\RemoveAttributeParenthesesVisitor;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

/**
 * PER Coding Style: an attribute that passes no argument omits the
 * parentheses after its name — `#[Foo]`, not `#[Foo()]`.
 *
 * @see https://www.php-fig.org/per/coding-style/#12-attributes
 */
final readonly class AttributeMayNotHaveEmptyParenthesesRule extends AbstractPhpParserFixableRule implements
    FileAnalysisRuleInterface
{
    private PhpFileFinder $phpFileFinder;

    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        ?array $sourcePaths = null,
        ?PhpFileFinder $phpFileFinder = null,
    ) {
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
        $files = $this->phpFileFinder->files($basePath, $architecture, $skipPaths);

        // Outside the analyser the attributes come from the same traversal it
        // runs, so there is a single place that recognises them.
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
                $architecture,
                $fileAnalysisProvider->scopeFiles(),
                $skipPaths,
            ),
            $fileAnalysisProvider,
        );
    }

    protected function createFixerVisitor(RuleViolation $ruleViolation): RemoveAttributeParenthesesVisitor
    {
        return new RemoveAttributeParenthesesVisitor($ruleViolation->line);
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

            foreach ($fileAnalysis->attributesWithEmptyParentheses as [$line, $attributeName]) {
                $violations[] = new RuleViolation(
                    message: sprintf('Attribute [%s] may not have empty parentheses', $attributeName),
                    file: $file,
                    line: $line,
                    className: '',
                );
            }
        }

        return $violations;
    }
}
