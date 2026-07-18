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
    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
        private ?PhpFileFinder $phpFileFinder = null,
    ) {
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
        return $this->evaluateProjectAllWithProvider(
            $basePath,
            $architecture,
            new FileAnalysisProvider(),
            $skipPaths,
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
        $phpFileFinder = $this->phpFileFinder ?? new PhpFileFinder($this->sourcePaths);
        $violations    = [];

        foreach ($fileAnalysisProvider->phpFiles($phpFileFinder, $basePath, $skipPaths) as $file) {
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
