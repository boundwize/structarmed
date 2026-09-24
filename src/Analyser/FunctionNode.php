<?php

declare(strict_types=1);

namespace Boundwize\StructArmed\Analyser;

use function array_filter;
use function preg_match;
use function strrpos;
use function substr;

/**
 * A named function declaration (`function foo() {}`), at the top level of a
 * file or a namespace. Closures and arrow functions are
 * {@see AnonymousFunctionNode}s instead.
 */
final class FunctionNode
{
    use NameQueryTrait;
    use NodeQueryTrait;

    /** @var list<string> */
    public readonly array $layers;

    /**
     * @param string       $functionName       Fully-qualified function name
     * @param list<string> $dependencies       Fully-qualified class, function, or constant dependencies
     * @param string[]     $functionCalls      Functions called within this function
     * @param string[]     $superglobals       Superglobals accessed ($_GET, $_POST, etc.)
     * @param string[]     $languageConstructs Language constructs used (exit, die, etc.)
     * @param list<string> $layers             All layer names this function belongs to; defaults to [$layer]
     */
    public function __construct(
        public readonly string $functionName,
        public readonly string $file,
        public readonly int $line,
        public readonly ?string $layer,
        public readonly bool $hasReturnType = false,
        public readonly int $paramCount = 0,
        public readonly int $cyclomaticComplexity = 1,
        public readonly int $lineCount = 0,
        public readonly array $dependencies = [],
        public readonly array $functionCalls = [],
        public readonly array $superglobals = [],
        public readonly array $languageConstructs = [],
        array $layers = [],
        public bool $isReferenced = false,
    ) {
        $this->layers = $layers ?: array_filter([$this->layer]);
    }

    /**
     * Whether another scanned scope references this function — a call, a
     * first-class callable, or a function-name string. Computed by the
     * analyser for rules implementing UsedFunctionAwareRuleInterface; false
     * otherwise.
     */
    public function setReferenced(bool $isReferenced): void
    {
        $this->isReferenced = $isReferenced;
    }

    public function shortName(): string
    {
        $position = strrpos($this->functionName, '\\');

        return $position === false
            ? $this->functionName
            : substr($this->functionName, $position + 1);
    }

    public function nameMatches(string $pattern, bool $isFullName = false): bool
    {
        return (bool) preg_match($pattern, $isFullName ? $this->functionName : $this->shortName());
    }
}
