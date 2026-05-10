<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function file_get_contents;
use function preg_match;
use function sprintf;

final readonly class Psr1PhpTagsRule implements ProjectRuleInterface
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

            if (preg_match('/<\?(?!php(?:\s|$)|=)/', $contents) === 1) {
                return new RuleViolation(
                    ruleKey: '',
                    message: sprintf('File [%s] must use only <?php and <?= PHP tags', $file),
                    file: $file,
                    line: 1,
                    className: '',
                );
            }
        }

        return null;
    }
}
