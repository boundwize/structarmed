<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Baseline;

use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use RuntimeException;

use function array_flip;
use function dirname;
use function file_exists;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_scalar;
use function json_encode;
use function ltrim;
use function preg_match;
use function realpath;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function var_export;

use const JSON_UNESCAPED_SLASHES;

final readonly class Baseline
{
    public function filter(
        RuleViolationCollection $ruleViolationCollection,
        string $baselinePath,
        string $basePath
    ): RuleViolationCollection {
        $signatures = array_flip($this->loadSignatures($baselinePath, $basePath));
        $filtered   = new RuleViolationCollection();

        foreach ($ruleViolationCollection as $violation) {
            if (isset($signatures[$this->signature($violation, $basePath)])) {
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

        $path      = $this->resolvePath($baselinePath, $basePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new RuntimeException(sprintf('Baseline directory [%s] does not exist.', $directory));
        }

        $violations = [];

        foreach ($ruleViolationCollection as $violation) {
            $violations[] = [
                'rule'    => $violation->ruleKey,
                'message' => $violation->message,
                'file'    => $this->relativePath($violation->file, $basePath),
                'line'    => $violation->line,
                'class'   => $violation->className,
                'layer'   => $violation->layer,
            ];
        }

        $content = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . 'return ' . var_export($violations, true) . ";\n";

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('Could not write baseline file [%s].', $baselinePath));
        }
    }

    /**
     * @return list<string>
     */
    private function loadSignatures(string $baselinePath, string $basePath): array
    {
        $path = $this->resolvePath($baselinePath, $basePath);

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

            $signatures[] = $this->arraySignature($violation, $basePath);
        }

        return $signatures;
    }

    /**
     * @param array<mixed, mixed> $violation
     */
    private function arraySignature(array $violation, string $basePath): string
    {
        return (string) json_encode([
            'rule'    => $this->stringValue($violation['rule'] ?? null),
            'message' => $this->stringValue($violation['message'] ?? null),
            'file'    => $this->relativePath($this->stringValue($violation['file'] ?? null), $basePath),
            'line'    => $violation['line'] ?? 0,
            'class'   => $this->stringValue($violation['class'] ?? null),
            'layer'   => $violation['layer'] ?? null,
        ], JSON_UNESCAPED_SLASHES);
    }

    private function stringValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return (string) $value;
    }

    private function signature(RuleViolation $ruleViolation, string $basePath): string
    {
        return (string) json_encode([
            'rule'    => $ruleViolation->ruleKey,
            'message' => $ruleViolation->message,
            'file'    => $this->relativePath($ruleViolation->file, $basePath),
            'line'    => $ruleViolation->line,
            'class'   => $ruleViolation->className,
            'layer'   => $ruleViolation->layer,
        ], JSON_UNESCAPED_SLASHES);
    }

    private function resolvePath(string $path, string $basePath): string
    {
        $normalisedPath = str_replace('\\', '/', $path);

        if (str_starts_with($normalisedPath, '/') || preg_match('/^[A-Za-z]:\//', $normalisedPath) === 1) {
            return $path;
        }

        return rtrim($basePath, '/') . '/' . ltrim($path, '/');
    }

    private function relativePath(string $path, string $basePath): string
    {
        $normalisedBasePath = rtrim(str_replace('\\', '/', realpath($basePath) ?: $basePath), '/');
        $normalisedPath     = str_replace('\\', '/', realpath($path) ?: $path);

        if ($normalisedPath === $normalisedBasePath) {
            return '';
        }

        if (str_starts_with($normalisedPath, $normalisedBasePath . '/')) {
            return substr($normalisedPath, strlen($normalisedBasePath) + 1);
        }

        return ltrim($normalisedPath, '/');
    }
}
