<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Rule\Rules\File;

use Boundwize\StructArmed\Analyser\FileAnalysisProvider;
use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Rule\FileAnalysisRuleInterface;
use Boundwize\StructArmed\Rule\FixableInterface;
use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Util\InlineHtmlOpeningTagMatcher;

use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function preg_match;
use function sprintf;
use function strlen;
use function substr;
use function substr_count;
use function token_get_all;

use const T_INLINE_HTML;
use const T_OPEN_TAG;

final readonly class Psr1PhpTagsRule implements FileAnalysisRuleInterface, FixableInterface
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

        foreach ($phpFileFinder->files($basePath, $skipPaths) as $file) {
            $invalidPhpTagLine = $fileAnalysisProvider->invalidPhpTagLine($file);

            if ($invalidPhpTagLine === null) {
                continue;
            }

            $violations[] = new RuleViolation(
                message: sprintf('File [%s] must use only <?php and <?= PHP tags', $file),
                file: $file,
                line: $invalidPhpTagLine,
                className: '',
            );
        }

        return $violations;
    }

    public function fix(RuleViolation $ruleViolation): bool
    {
        if (! is_file($ruleViolation->file)) {
            return false;
        }

        $code      = (string) file_get_contents($ruleViolation->file);
        $fixedCode = $this->fixInvalidTagOnLine($code, $ruleViolation->line);

        if ($fixedCode === null || $fixedCode === $code) {
            return false;
        }

        return file_put_contents($ruleViolation->file, $fixedCode) !== false;
    }

    private function fixInvalidTagOnLine(string $code, int $line): ?string
    {
        $offset = 0;

        foreach (token_get_all($code) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (is_array($token)) {
                $replacement = $this->replacementForInvalidTag($token[0], $text, $token[2], $line);

                if ($replacement !== null) {
                    $replaceAt   = $offset + $replacement['offset'];
                    $afterOffset = $replaceAt + $replacement['length'];

                    return substr($code, 0, $replaceAt)
                        . $this->normalizedTagReplacement($replacement['text'], substr($code, $afterOffset, 1))
                        . substr($code, $afterOffset);
                }
            }

            $offset += strlen($text);
        }

        return null;
    }

    /**
     * Ensures the replaced `<?php` tag keeps a separator before the following
     * code. Short open tags such as `<?echo` tokenize as `<?` with the next
     * character in a separate token, so `<?php` alone would produce `<?phpecho`.
     */
    private function normalizedTagReplacement(string $text, string $nextChar): string
    {
        if ($text === '<?php' && $nextChar !== '' && preg_match('/^\s/', $nextChar) !== 1) {
            return '<?php ';
        }

        return $text;
    }

    /**
     * @return array{offset: int, length: int, text: string}|null
     */
    private function replacementForInvalidTag(int $id, string $text, int $tokenLine, int $targetLine): ?array
    {
        if ($id === T_OPEN_TAG) {
            if ($tokenLine !== $targetLine || ! $this->isInvalidOpenTag($text)) {
                return null;
            }

            $length = preg_match('/^<\?php/i', $text, $matches) === 1 ? strlen($matches[0]) : 2;

            return [
                'offset' => 0,
                'length' => $length,
                'text'   => '<?php',
            ];
        }

        if ($id !== T_INLINE_HTML) {
            return null;
        }

        $searchOffset = 0;

        while (($tagOffset = InlineHtmlOpeningTagMatcher::invalidInlineHtmlTagOffset($text, $searchOffset)) !== null) {
            $tagLine = $tokenLine + substr_count(substr($text, 0, $tagOffset), "\n");

            if ($tagLine === $targetLine) {
                return [
                    'offset' => $tagOffset,
                    'length' => 2,
                    'text'   => '<?php',
                ];
            }

            $searchOffset = $tagOffset + 2;
        }

        return null;
    }

    private function isInvalidOpenTag(string $text): bool
    {
        return preg_match('/^<\?php(?:\s|$)/', $text) !== 1;
    }
}
