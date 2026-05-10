<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function file_get_contents;
use function preg_match;
use function sprintf;
use function str_starts_with;

final readonly class Psr1Utf8WithoutBomRule implements ProjectRuleInterface
{
    /**
     * @param list<string>|null $sourcePaths
     */
    public function __construct(
        private ?array $sourcePaths = null,
        private ?PhpFileFinder $phpFileFinder = null,
    ) {
    }

    public function evaluateProject(string $basePath, Architecture $architecture): ?RuleViolation
    {
        $phpFileFinder = $this->phpFileFinder ?? new PhpFileFinder($this->sourcePaths);

        foreach ($phpFileFinder->files($basePath) as $file) {
            $contents = (string) file_get_contents($file);

            if (str_starts_with($contents, "\xEF\xBB\xBF")) {
                return new RuleViolation(
                    ruleKey: '',
                    message: sprintf('File [%s] must use UTF-8 without BOM', $file),
                    file: $file,
                    line: 1,
                    className: '',
                );
            }

            if (preg_match('//u', $contents) !== 1) {
                return new RuleViolation(
                    ruleKey: '',
                    message: sprintf('File [%s] must use valid UTF-8 encoding', $file),
                    file: $file,
                    line: 1,
                    className: '',
                );
            }
        }

        return null;
    }
}
