<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Baseline;

use Boundwize\StructArmed\Rule\RuleViolation;
use Boundwize\StructArmed\Rule\RuleViolationCollection;
use Boundwize\StructArmed\Util\Path;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

use function array_flip;
use function assert;
use function dirname;
use function file_exists;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_scalar;
use function json_encode;
use function ltrim;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function var_export;

use const JSON_INVALID_UTF8_SUBSTITUTE;
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

        $path      = Path::resolve($baselinePath, $basePath);
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
                'class'   => $violation->className,
                'layer'   => $violation->layer,
            ];
        }

        $header  = "<?php\n\n"
            . "declare(strict_types=1);\n\n";
        $content = $header . 'return ' . var_export($violations, true) . ";\n";
        $content = $this->prettyPrintContent($header, $content);

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('Could not write baseline file [%s].', $baselinePath));
        }
    }

    private function prettyPrintContent(string $header, string $content): string
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($content) ?? [];

        $statements = (new NodeTraverser(new class extends NodeVisitorAbstract {
            public function enterNode(Node $node): ?Node
            {
                if (! $node instanceof Array_) {
                    return null;
                }

                if (! $this->isListArray($node)) {
                    return $node;
                }

                foreach ($node->items as $item) {
                    $item->key = null;
                }

                return $node;
            }

            private function isListArray(Array_ $array): bool
            {
                foreach ($array->items as $index => $item) {
                    if (! $item->key instanceof Int_ || $item->key->value !== $index) {
                        return false;
                    }
                }

                return true;
            }
        }))->traverse($statements);

        $array = null;

        foreach ($statements as $statement) {
            if ($statement instanceof Return_ && $statement->expr instanceof Array_) {
                $array = $statement->expr;

                break;
            }
        }

        assert($array instanceof Array_);

        return $header . 'return ' . $this->prettyPrintArray($array) . ";\n";
    }

    private function prettyPrintArray(Array_ $array): string
    {
        return (new class () extends Standard {
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
     * @return list<string>
     */
    private function loadSignatures(string $baselinePath, string $basePath): array
    {
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

    private function signature(RuleViolation $ruleViolation, string $basePath): string
    {
        return (string) json_encode([
            'rule'    => $ruleViolation->ruleKey,
            'message' => $ruleViolation->message,
            'file'    => $this->relativePath($ruleViolation->file, $basePath),
            'class'   => $ruleViolation->className,
            'layer'   => $ruleViolation->layer,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function relativePath(string $path, string $basePath): string
    {
        $normalisedBasePath = Path::normalise(realpath($basePath) ?: $basePath);
        $normalisedPath     = Path::normalise(realpath($path) ?: $path);

        if ($normalisedPath === $normalisedBasePath) {
            return '';
        }

        if (str_starts_with($normalisedPath, $normalisedBasePath . '/')) {
            return substr($normalisedPath, strlen($normalisedBasePath) + 1);
        }

        return ltrim($normalisedPath, '/');
    }
}
