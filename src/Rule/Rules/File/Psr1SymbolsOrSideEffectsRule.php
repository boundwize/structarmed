<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FileAnalysisRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class Psr1SymbolsOrSideEffectsRule implements FileAnalysisRuleInterface
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
        return $this->evaluateFiles(
            $this->phpFileFinder->files($basePath, $skipPaths),
            new FileAnalysisProvider(),
        );
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

            if (! $fileAnalysis->hasValidAst) {
                continue;
            }

            if ($fileAnalysis->declaresSymbols && $fileAnalysis->hasSideEffects) {
                $violations[] = new RuleViolation(
                    message: sprintf(
                        'File [%s] should either declare symbols or cause side effects, not both',
                        $file
                    ),
                    file: $file,
                    line: $fileAnalysis->sideEffectLine,
                    className: '',
                );
            }
        }

        return $violations;
    }
}
