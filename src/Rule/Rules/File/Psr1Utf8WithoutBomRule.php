<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\RuleViolation;

use function sprintf;

final readonly class Psr1Utf8WithoutBomRule implements FileAnalysisRuleInterface
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
     * @param string[] $skipPaths
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

        foreach ($phpFileFinder->files($basePath, $skipPaths) as $file) {
            if ($fileAnalysisProvider->hasUtf8Bom($file)) {
                $violations[] = new RuleViolation(
                    message: sprintf('File [%s] must use UTF-8 without BOM', $file),
                    file: $file,
                    line: 1,
                    className: '',
                );
                continue;
            }

            if (! $fileAnalysisProvider->hasValidUtf8($file)) {
                $violations[] = new RuleViolation(
                    message: sprintf('File [%s] must use valid UTF-8 encoding', $file),
                    file: $file,
                    line: 1,
                    className: '',
                );
            }
        }

        return $violations;
    }
}
