<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Analyser\AnalysisNodeExtractor;
use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FileAnalysisRuleInterface;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\AbstractPhpParserFixableRule;
use Boundwize\StructArmed\Rule\Fixer\PhpParser\ConstFetch\LowercaseKeywordConstantVisitor;
use Boundwize\StructArmed\Rule\RuleViolation;
use PhpParser\NodeVisitor;

use function ltrim;
use function sprintf;
use function strtolower;

/**
 * Requires the keyword constants `true`, `false`, and `null` to use their
 * canonical lowercase spelling. Only the spelling is checked and fixed: a
 * fully qualified `\TRUE` stays fully qualified and becomes `\true`.
 */
final readonly class MustUseLowercaseKeywordConstantRule extends AbstractPhpParserFixableRule implements
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
     * @return RuleViolation[]
     */
    public function evaluateProjectAll(string $basePath, Architecture $architecture, array $skipPaths = []): array
    {
        $files = $this->phpFileFinder->files($basePath, $skipPaths);

        // Outside the analyser the spellings come from the same traversal it
        // runs, so there is a single place that recognises them.
        $extractionResult = (new AnalysisNodeExtractor())->extract($files);

        return $this->evaluateFiles($files, new FileAnalysisProvider($extractionResult->fileAnalyses));
    }

    /**
     * @param list<string> $skipPaths
     * @return RuleViolation[]
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
        return new LowercaseKeywordConstantVisitor($ruleViolation->line, $ruleViolation->constantName);
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

            foreach ($fileAnalysis->nonCanonicalKeywordConstants as [$line, $spelling]) {
                $violations[] = new RuleViolation(
                    message: sprintf(
                        'Keyword constant [%s] must use lowercase [%s]',
                        $spelling,
                        strtolower($spelling),
                    ),
                    file: $file,
                    line: $line,
                    className: '',
                    constantName: ltrim($spelling, '\\'),
                );
            }
        }

        return $violations;
    }
}
