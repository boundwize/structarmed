<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\MultipleProjectRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function file_get_contents;
use function preg_match;
use function sprintf;
use function str_starts_with;

final readonly class Psr1Utf8WithoutBomRule implements MultipleProjectRuleViolationInterface
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
        $phpFileFinder = $this->phpFileFinder ?? new PhpFileFinder($this->sourcePaths);
        $violations    = [];

        foreach ($phpFileFinder->files($basePath, $skipPaths) as $file) {
            $contents = (string) file_get_contents($file);

            if (str_starts_with($contents, "\xEF\xBB\xBF")) {
                $violations[] = new RuleViolation(
                    message: sprintf('File [%s] must use UTF-8 without BOM', $file),
                    file: $file,
                    line: 1,
                    className: '',
                );
                continue;
            }

            if (preg_match('//u', $contents) !== 1) {
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
