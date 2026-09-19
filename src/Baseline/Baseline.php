<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Baseline;

use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Util\Path;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\Array_;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

use function array_keys;
use function assert;
use function dirname;
use function file_exists;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_scalar;
use function json_encode;
use function rtrim;
use function sprintf;
use function str_replace;

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
            $relativeFile = Path::relativeTo($violation->file, $normalisedBasePath);
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
        $expr   = (new BuilderFactory())->val($violations);

        assert($expr instanceof Array_);

        $content = $header . 'return ' . $this->prettyPrintArray($expr) . ";\n";

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
        $relativeFile = Path::relativeTo($file, $normalisedBasePath);

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
        $relativeFile = Path::relativeTo($ruleViolation->file, $normalisedBasePath);

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
}
