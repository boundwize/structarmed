<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\MultipleProjectRuleViolationInterface;
use Boundwize\StructArmed\Rule\RuleViolation;

use function file_get_contents;
use function is_array;
use function preg_match;
use function sprintf;
use function token_get_all;

use const T_INLINE_HTML;
use const T_OPEN_TAG;
use const T_OPEN_TAG_WITH_ECHO;

final readonly class Psr1PhpTagsRule implements MultipleProjectRuleViolationInterface
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

                $violations[] = new RuleViolation(
                    message: sprintf('File [%s] must use only <?php and <?= PHP tags', $file),
                    file: $file,
                    line: $token[2],
                    className: '',
                );
                continue 2;
            }
        }

        return $violations;
    }
}
