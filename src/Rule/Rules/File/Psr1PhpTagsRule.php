<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\ProjectRuleInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function file_get_contents;
use function is_array;
use function preg_match;
use function sprintf;
use function token_get_all;

use const T_INLINE_HTML;
use const T_OPEN_TAG;
use const T_OPEN_TAG_WITH_ECHO;

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

    public function evaluateProject(string $basePath, Architecture $architecture, array $skipPaths = []): ?RuleViolation
    {
        $phpFileFinder = $this->phpFileFinder ?? new PhpFileFinder($this->sourcePaths);

        foreach ($phpFileFinder->files($basePath, $skipPaths) as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (! is_array($token)) {
                    continue;
                }

                if ($token[0] === T_OPEN_TAG_WITH_ECHO) {
                    continue;
                }

                if ($token[0] === T_OPEN_TAG && preg_match('/^<\?php(?:\s|$)/', $token[1]) === 1) {
                    continue;
                }

                if (
                    $token[0] !== T_OPEN_TAG
                    && ($token[0] !== T_INLINE_HTML || preg_match('/<\?(?!php(?:\s|$)|=)/', $token[1]) !== 1)
                ) {
                    continue;
                }

                return new RuleViolation(
                    message: sprintf('File [%s] must use only <?php and <?= PHP tags', $file),
                    file: $file,
                    line: $token[2],
                    className: '',
                );
            }
        }

        return null;
    }
}
