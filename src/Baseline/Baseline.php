<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Baseline;

use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Util\Path;
use PhpParser\BuilderHelpers;
use PhpParser\Node\Expr\Array_;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

use function array_keys;
use function array_slice;
use function assert;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_scalar;
use function json_encode;
use function rtrim;
use function sprintf;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;

final readonly class Baseline
{
    public function filter(
        RuleViolationCollection $ruleViolationCollection,
        string $baselinePath,
        string $basePath
    ): RuleViolationCollection {
        $normalisedBasePath  = Path::normalise($basePath, canonicalise: true);
        $messagePathPrefixes = $this->messagePathPrefixes($basePath, $normalisedBasePath);
        $signatures          = $this->loadSignatures(
            $baselinePath,
            $basePath,
            $normalisedBasePath,
            $messagePathPrefixes,
        );
        $filtered            = new RuleViolationCollection();

        foreach ($ruleViolationCollection as $violation) {
            if (isset($signatures[$this->signature($violation, $normalisedBasePath, $messagePathPrefixes)])) {
                continue;
            }

            $filtered->add($violation);
        }

        return $filtered;
    }

    public function generate(
        RuleViolationCollection $ruleViolationCollection,
        string $baselinePath,
        string $basePath
    ): void {
        if ($baselinePath === '') {
            throw new RuntimeException('Baseline path cannot be empty.');
        }

        $path      = Path::resolve($baselinePath, $basePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new RuntimeException(sprintf('Baseline directory [%s] does not exist.', $directory));
        }

        $normalisedBasePath  = Path::normalise($basePath, canonicalise: true);
        $messagePathPrefixes = $this->messagePathPrefixes($basePath, $normalisedBasePath);
        $violations          = [];

        foreach ($ruleViolationCollection as $violation) {
            $relativeFile = $this->relativePath($violation->file, $normalisedBasePath);
            $violations[] = [
                'rule'    => $violation->ruleKey,
                'message' => $this->relativeMessagePath(
                    $violation->message,
                    $violation->file,
                    $relativeFile,
                    $messagePathPrefixes,
                ),
                'file'    => $relativeFile,
                'class'   => $violation->className,
                'layer'   => $violation->layer,
            ];
        }

        $header = "<?php\n\n"
            . "declare(strict_types=1);\n\n";
        $array  = BuilderHelpers::normalizeValue($violations);

        assert($array instanceof Array_);

        $content = $header . 'return ' . $this->prettyPrintArray($array) . ";\n";

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('Could not write baseline file [%s].', $baselinePath));
        }
    }

    private function prettyPrintArray(Array_ $array): string
    {
        return (new class extends Standard {
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            protected function pExpr_Array(Array_ $node): string
            {
                if ($node->items === []) {
                    return '[]';
                }

                return '[' . $this->pCommaSeparatedMultiline($node->items, true) . $this->nl . ']';
            }
        })->prettyPrintExpr($array);
    }

    /**
     * @param list<string> $messagePathPrefixes
     * @return array<string, true>
     */
    private function loadSignatures(
        string $baselinePath,
        string $basePath,
        string $normalisedBasePath,
        array $messagePathPrefixes,
    ): array {
        $path = Path::resolve($baselinePath, $basePath);

        if (! file_exists($path)) {
            throw new RuntimeException(sprintf('Baseline file [%s] does not exist.', $baselinePath));
        }

        $violations = require $path;

        if (! is_array($violations)) {
            throw new RuntimeException(sprintf('Baseline file [%s] must return an array.', $baselinePath));
        }

        $signatures = [];

        foreach ($violations as $violation) {
            if (! is_array($violation)) {
                continue;
            }

            $signatures[$this->arraySignature($violation, $normalisedBasePath, $messagePathPrefixes)] = true;
        }

        return $signatures;
    }

    /**
     * @param array<mixed, mixed> $violation
     * @param list<string> $messagePathPrefixes
     */
    private function arraySignature(
        array $violation,
        string $normalisedBasePath,
        array $messagePathPrefixes,
    ): string {
        $file         = $this->stringValue($violation['file'] ?? null);
        $relativeFile = $this->relativePath($file, $normalisedBasePath);

        return (string) json_encode([
            'rule'    => $this->stringValue($violation['rule'] ?? null),
            'message' => $this->relativeMessagePath(
                $this->stringValue($violation['message'] ?? null),
                $file,
                $relativeFile,
                $messagePathPrefixes,
            ),
            'file'    => $relativeFile,
            'class'   => $this->stringValue($violation['class'] ?? null),
            'layer'   => $violation['layer'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function stringValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return (string) $value;
    }

    /** @param list<string> $messagePathPrefixes */
    private function signature(
        RuleViolation $ruleViolation,
        string $normalisedBasePath,
        array $messagePathPrefixes,
    ): string {
        $relativeFile = $this->relativePath($ruleViolation->file, $normalisedBasePath);

        return (string) json_encode([
            'rule'    => $ruleViolation->ruleKey,
            'message' => $this->relativeMessagePath(
                $ruleViolation->message,
                $ruleViolation->file,
                $relativeFile,
                $messagePathPrefixes,
            ),
            'file'    => $relativeFile,
            'class'   => $ruleViolation->className,
            'layer'   => $ruleViolation->layer,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @param list<string> $messagePathPrefixes */
    private function relativeMessagePath(
        string $message,
        string $file,
        string $relativeFile,
        array $messagePathPrefixes,
    ): string {
        return str_replace(
            $messagePathPrefixes,
            '',
            str_replace($file, $relativeFile, $message),
        );
    }

    /** @return list<string> */
    private function messagePathPrefixes(string $basePath, string $normalisedBasePath): array
    {
        $prefixes = [];

        // The message may spell the path differently from `file` (unresolved "..",
        // backslashes on Windows, ...), so strip the base path itself too.
        foreach ([rtrim($basePath, '/\\'), $normalisedBasePath] as $base) {
            if ($base === '') {
                continue;
            }

            $prefixes[$base . '/']                          = true;
            $prefixes[str_replace('/', '\\', $base) . '\\'] = true;
        }

        return array_keys($prefixes);
    }

    private function relativePath(string $path, string $normalisedBasePath): string
    {
        $normalisedPath = Path::normalise($path, canonicalise: true);

        if ($normalisedPath === $normalisedBasePath) {
            return '';
        }

        if (str_starts_with($normalisedPath, $normalisedBasePath . '/')) {
            return substr($normalisedPath, strlen($normalisedBasePath) + 1);
        }

        // Out-of-tree file (e.g. a PSR-4 path such as "../shared/src/"): walk up to the
        // common ancestor so the baseline stays portable between checkouts.
        $baseSegments = explode('/', $normalisedBasePath);
        $pathSegments = explode('/', $normalisedPath);
        $common       = 0;

        while (
            isset($baseSegments[$common], $pathSegments[$common])
            && $baseSegments[$common] === $pathSegments[$common]
        ) {
            ++$common;
        }

        if ($common === 0) {
            return $normalisedPath;
        }

        return str_repeat('../', count($baseSegments) - $common) . implode('/', array_slice($pathSegments, $common));
    }
}
